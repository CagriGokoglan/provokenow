<?php

namespace App\Services\Gifts;

use App\Enums\GiftStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Mail\GiftReceivedMailable;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Support\Tracking\TrackingContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Gifting: a single product bought for someone else's email. On payment the recipient is
 * emailed (now, or on the chosen date); digital decks are claimed into their Vault with an
 * 18+ confirmation, physical items ship to the address the buyer entered at checkout.
 */
class GiftService
{
    /** Scheduled gifts go out at this local time on the chosen day (US market). */
    public const DELIVERY_HOUR = 9;

    public const DELIVERY_TIMEZONE = 'America/New_York';

    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * Snapshots a one-item gift order (list or sale price; no bundle or owner credits).
     *
     * @param  array{recipient_email: string, sender_alias?: ?string, gift_message?: ?string, deliver_on?: ?string}  $details
     */
    public function createGiftOrder(User $sender, Product $product, array $details, string $gateway, ?TrackingContext $tracking = null): Order
    {
        if (! $product->is_active || ! $product->hasStockFor(1)) {
            throw new BusinessRuleException("{$product->name} is not available right now.", 409);
        }

        $price = $product->effectivePriceCents();
        $requiresShipping = $product->requiresShipping();

        return DB::transaction(function () use ($sender, $product, $details, $gateway, $tracking, $price, $requiresShipping) {
            $order = Order::create([
                'user_id' => $sender->getKey(),
                'customer_email' => $sender->email,
                'subtotal_cents' => $price,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'shipping_cents' => 0,
                'total_cents' => $price,
                'currency' => config('provoke.commerce.currency'),
                'payment_status' => PaymentStatus::Pending,
                'payment_gateway' => $gateway,
                'metadata' => [
                    'requires_shipping' => $requiresShipping,
                    'is_gift' => true,
                    'tracking' => $tracking?->consent ? $tracking->toArray() : null,
                ],
            ]);

            $order->items()->create([
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'unit_price_cents' => $price,
                'subtotal_cents' => $price,
                'metadata' => [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'product_type' => $product->product_type->value,
                    'list_price_cents' => $product->price_cents,
                    'applied_discount_cents' => $product->price_cents - $price,
                    'digital_credit_cents' => 0,
                    'upsell_bundle' => false,
                    'deck_ids' => $product->decks->modelKeys(),
                    'requires_shipping' => $requiresShipping,
                ],
            ]);

            Gift::create([
                'order_id' => $order->getKey(),
                'sender_id' => $sender->getKey(),
                'recipient_email' => mb_strtolower(trim($details['recipient_email'])),
                'sender_alias' => filled($details['sender_alias'] ?? null) ? trim($details['sender_alias']) : null,
                'gift_message' => filled($details['gift_message'] ?? null) ? trim($details['gift_message']) : null,
                'deliver_at' => $this->deliveryTime($details['deliver_on'] ?? null),
                'status' => GiftStatus::Pending,
            ]);

            return $order->load('items', 'gift');
        });
    }

    /** Called once the gift's order is paid: send it now unless it was scheduled. */
    public function orderPaid(Order $order): void
    {
        $gift = $order->gift;
        if ($gift && $gift->status === GiftStatus::Pending && ($gift->deliver_at === null || $gift->deliver_at->isPast())) {
            $this->deliver($gift);
        }
    }

    /** Scheduled gifts whose day has come (run by the scheduler). */
    public function deliverDue(): int
    {
        $due = Gift::where('status', GiftStatus::Pending)
            ->where(fn ($q) => $q->whereNull('deliver_at')->orWhere('deliver_at', '<=', now()))
            ->whereHas('order', fn ($q) => $q->where('payment_status', PaymentStatus::Paid))
            ->get();

        $due->each(fn (Gift $gift) => $this->deliver($gift));

        return $due->count();
    }

    /**
     * Emails the recipient. Registered: the gift is waiting in their account. Not registered: an
     * invitation with a claim link. Physical-only gifts: a heads-up that a parcel is coming.
     * Re-sending issues a new link and restarts the claim window.
     */
    public function deliver(Gift $gift): Gift
    {
        $gift->loadMissing('order.items');
        $token = null;

        DB::transaction(function () use ($gift, &$token) {
            $recipient = User::firstWhere('email', $gift->recipient_email);
            $token = $gift->hasVaultItems() ? $gift->issueClaimToken() : null;

            $gift->fill([
                'recipient_id' => $gift->recipient_id ?? $recipient?->getKey(),
                'status' => GiftStatus::Sent,
                'sent_at' => now(),
                'expires_at' => $gift->hasVaultItems() ? now()->addDays(Gift::CLAIM_DAYS) : null,
            ])->save();
        });

        Mail::to($gift->recipient_email)->queue(new GiftReceivedMailable($gift->fresh(['order.items', 'recipient']), $token));

        return $gift;
    }

    /**
     * Links a gift to the account opening its claim link. A gift addressed to an existing account
     * can only go to that account; an invitation can be redeemed by whoever registers with it.
     */
    public function attach(Gift $gift, User $user): Gift
    {
        if (! $gift->isClaimable()) {
            throw new BusinessRuleException($this->unavailableMessage($gift), 410);
        }

        $addressedTo = $gift->recipient_id ?? User::where('email', $gift->recipient_email)->value('id');
        if ($addressedTo && $addressedTo !== $user->getKey()) {
            throw new BusinessRuleException("This gift was sent to another account. Sign in as {$this->maskEmail($gift->recipient_email)} to claim it.", 403);
        }

        $gift->update(['recipient_id' => $user->getKey()]);

        return $gift;
    }

    /** "Confirm +18 & Claim Gift": records the age confirmation and puts the decks in the Vault. */
    public function claim(Gift $gift, User $user): Gift
    {
        return DB::transaction(function () use ($gift, $user) {
            $gift = Gift::whereKey($gift->getKey())->lockForUpdate()->with('order.items')->firstOrFail();

            if ($gift->recipient_id !== $user->getKey()) {
                throw new BusinessRuleException('This gift belongs to another account.', 403);
            }
            if ($gift->status === GiftStatus::Claimed) {
                return $gift;
            }
            if (! $gift->isClaimable()) {
                throw new BusinessRuleException($this->unavailableMessage($gift), 410);
            }

            $this->entitlements->grant($user, $gift->deckIds(), $gift->order, gift: $gift);
            $gift->update(['status' => GiftStatus::Claimed, 'claimed_at' => now(), 'claim_token_hash' => null]);
            $user->forceFill(['age_confirmed_at' => now()])->save();

            return $gift;
        });
    }

    /**
     * Payment failed or refunded in full: an unclaimed gift can no longer be claimed (decks
     * already claimed are revoked with the order's other entitlements).
     */
    public function cancelFor(Order $order): void
    {
        Gift::where('order_id', $order->getKey())
            ->whereIn('status', [GiftStatus::Pending, GiftStatus::Sent])
            ->update(['status' => GiftStatus::Cancelled, 'claim_token_hash' => null, 'updated_at' => now()]);
    }

    /** Sent gifts nobody claimed in time. */
    public function expireOverdue(): int
    {
        return Gift::where('status', GiftStatus::Sent)->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->update(['status' => GiftStatus::Expired, 'claim_token_hash' => null, 'updated_at' => now()]);
    }

    public function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).str_repeat('•', max(2, mb_strlen($name) - 1)).'@'.$domain;
    }

    private function unavailableMessage(Gift $gift): string
    {
        return match ($gift->status) {
            GiftStatus::Claimed => 'This gift has already been claimed.',
            GiftStatus::Cancelled => 'This gift is no longer available.',
            default => 'This gift link has expired. Ask the sender, or our support team, to send it again.',
        };
    }

    /** "2026-12-24" → 09:00 New York time that day; today or empty → right away. */
    private function deliveryTime(?string $day): ?CarbonImmutable
    {
        if (blank($day)) {
            return null;
        }

        $at = CarbonImmutable::parse($day, self::DELIVERY_TIMEZONE)->setTime(self::DELIVERY_HOUR, 0);

        return $at->isPast() ? null : $at->utc();
    }
}
