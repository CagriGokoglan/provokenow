<?php

namespace App\Http\Controllers\Api\V1\Gifts;

use App\Contracts\PaymentGateway;
use App\Enums\GiftStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\DeckController;
use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\Product;
use App\Models\User;
use App\Services\Commerce\OrderFulfillmentService;
use App\Services\Gifts\GiftService;
use App\Support\ApiResponse;
use App\Support\Tracking\TrackingContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Buying a gift, opening a claim link, and claiming into the Vault. */
class GiftController extends Controller
{
    /** "Send as a gift": a one-item order for someone else, straight to secure checkout. */
    public function checkout(Request $request, GiftService $gifts, PaymentGateway $gateway, OrderFulfillmentService $fulfillment): JsonResponse
    {
        if (! $gateway->isConfigured()) {
            return ApiResponse::error('Payments are not available yet.', 503);
        }

        /** @var User $sender */
        $sender = $request->user();
        $today = CarbonImmutable::now(GiftService::DELIVERY_TIMEZONE)->toDateString();
        $data = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:255', 'not_in:'.$sender->email],
            'sender_alias' => ['nullable', 'string', 'max:60'],
            'gift_message' => ['nullable', 'string', 'max:500'],
            'deliver_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before:'.CarbonImmutable::parse($today)->addYear()->toDateString()],
        ], [
            'recipient_email.not_in' => 'Enter the recipient’s email address, not your own.',
            'deliver_on.after_or_equal' => 'Choose today or a later date.',
        ]);

        $product = Product::with('decks')->findOrFail($data['product_id']);
        if ($product->isAdultTier() && ! $sender->hasAdultAccess()) {
            return ApiResponse::error('This item is for verified members with Passion mode (18+) switched on.', 403, ['reason' => [DeckController::LOCK_ADULT]]);
        }

        $order = $gifts->createGiftOrder($sender, $product, $data, $gateway->name(), TrackingContext::fromRequest($request));

        try {
            $redirect = $gateway->createCheckout($order);
        } catch (Throwable $e) {
            report($e);
            $fulfillment->markFailed($order, 'gateway_session_error');

            return ApiResponse::error('We could not start the secure checkout. Please try again.', 502);
        }

        $order->update(['metadata' => [
            ...($order->metadata ?? []),
            'checkout_session_id' => $redirect->reference,
            "{$gateway->name()}_session_id" => $redirect->reference,
        ]]);

        return ApiResponse::success([
            'checkout_url' => $redirect->url,
            'order' => ['id' => $order->id, 'order_number' => $order->order_number, 'total_cents' => $order->total_cents, 'currency' => $order->currency],
        ], 'Redirecting to secure checkout.', 201);
    }

    /** What a claim link shows before sign-in: who it's from, nothing about the content. */
    public function show(string $token, GiftService $gifts): JsonResponse
    {
        $gift = Gift::findByToken($token) ?? abort(404);

        return ApiResponse::success([
            'from' => $gift->fromName(),
            'status' => $gift->status->value,
            'claimable' => $gift->isClaimable(),
            'recipient_has_account' => ($gift->recipient_id ?? User::where('email', $gift->recipient_email)->value('id')) !== null,
            'recipient_email' => $gifts->maskEmail($gift->recipient_email),
            'expires_at' => $gift->expires_at?->toIso8601String(),
        ], 'Gift.')->header('Cache-Control', 'no-store');
    }

    /** Opening a claim link while signed in links the gift to this account. */
    public function attach(Request $request, GiftService $gifts): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $gift = Gift::findByToken($data['token']) ?? throw new BusinessRuleException('This gift link is not valid.', 404);

        $gifts->attach($gift, $request->user());

        return ApiResponse::success(['gift' => $this->present($gift)], 'Gift ready to claim.');
    }

    /** Gifts waiting for this account's 18+ confirmation (drives the claim pop-up). */
    public function pending(Request $request): JsonResponse
    {
        $gifts = Gift::where('recipient_id', $request->user()->getKey())
            ->where('status', GiftStatus::Sent)
            ->whereNotNull('claim_token_hash')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('order.items')
            ->oldest('sent_at')
            ->get()
            ->filter(fn (Gift $g) => $g->hasVaultItems())
            ->values();

        return ApiResponse::success(['gifts' => $gifts->map(fn (Gift $g) => $this->present($g))], 'Gifts waiting.');
    }

    /** "Confirm +18 & Claim Gift". */
    public function claim(Request $request, string $id, GiftService $gifts): JsonResponse
    {
        $request->validate(['confirm_age' => ['accepted']], ['confirm_age.accepted' => 'Please confirm you are 18 or over.']);
        $gift = Gift::findOrFail($id);

        $gifts->claim($gift, $request->user());

        return ApiResponse::success(['gift' => $this->present($gift->fresh())], 'Gift claimed. It is waiting in your Vault.');
    }

    private function present(Gift $gift): array
    {
        return ['id' => $gift->id, 'from' => $gift->fromName(), 'status' => $gift->status->value];
    }
}
