<?php

namespace App\Services\Commerce;

use App\Enums\EntitlementStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Exceptions\BusinessRuleException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Services\Access\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs when a payment is confirmed. Called by the test endpoint today and by the real
 * gateway webhook later. Idempotent: confirming an already-paid order changes nothing.
 */
class OrderFulfillmentService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function markPaid(Order $order, string $gateway, string $transactionId): Order
    {
        return DB::transaction(function () use ($order, $gateway, $transactionId) {
            /** @var Order $order */
            $order = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->isPaid()) {
                return $order->load('items', 'entitlements.deck');
            }

            if ($order->payment_status === PaymentStatus::Refunded) {
                throw new BusinessRuleException('A refunded order cannot be marked as paid.', 409);
            }

            $order->load('items.product.decks');

            $this->decrementStock($order);
            $this->grantDeckAccess($order);

            $requiresShipping = $order->items->contains(fn ($item) => $item->product->requiresShipping());

            $order->update([
                'payment_status' => PaymentStatus::Paid,
                'payment_gateway' => $gateway,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
                // Digital-only orders are delivered the moment access is granted.
                'fulfillment_status' => $requiresShipping ? FulfillmentStatus::Unfulfilled : FulfillmentStatus::Delivered,
            ]);

            $this->clearPurchasedCartItems($order);
            $order->load('items', 'entitlements.deck');

            // Listeners queue their jobs after commit, so a rollback sends nothing.
            OrderPaid::dispatch($order);

            return $order;
        });
    }

    /** Payment did not complete (session expired, async payment failed). Only affects pending orders. */
    public function markFailed(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            /** @var Order $order */
            $order = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->payment_status === PaymentStatus::Pending) {
                $order->update([
                    'payment_status' => PaymentStatus::Failed,
                    'metadata' => [...($order->metadata ?? []), 'failure_reason' => $reason],
                ]);
            }

            return $order;
        });
    }

    /**
     * Records a refund from the provider. A full refund revokes the deck access this order granted
     * (revoked rows stay as history); partial refunds are only recorded.
     */
    public function recordRefund(Order $order, int $refundedCents, bool $full): Order
    {
        return DB::transaction(function () use ($order, $refundedCents, $full) {
            /** @var Order $order */
            $order = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $metadata = [...($order->metadata ?? []), 'refunded_cents' => $refundedCents];

            if (! $full) {
                $order->update(['metadata' => $metadata]);

                return $order;
            }

            $order->update(['payment_status' => PaymentStatus::Refunded, 'metadata' => $metadata]);
            $order->entitlements()
                ->where('status', EntitlementStatus::Active->value)
                ->update(['status' => EntitlementStatus::Revoked->value, 'updated_at' => now()]);

            return $order;
        });
    }

    private function decrementStock(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($item->product->hasUnlimitedStock()) {
                continue;
            }

            $updated = Product::whereKey($item->product_id)
                ->where('stock_quantity', '>=', $item->quantity)
                ->decrement('stock_quantity', $item->quantity);

            if ($updated === 0) {
                // Paid but oversold: keep the payment, flag the line for manual handling.
                $item->update(['metadata' => [...($item->metadata ?? []), 'backordered' => true]]);
                Log::warning('Order item oversold', ['order' => $order->order_number, 'product_id' => $item->product_id]);
            }
        }
    }

    private function grantDeckAccess(Order $order): void
    {
        $deckIds = $order->items->flatMap(fn ($item) => $item->product->decks->modelKeys())->unique()->values()->all();

        if ($deckIds === []) {
            return;
        }

        if ($order->user_id === null) {
            // Checkout blocks guests from buying deck access, so this should never happen.
            Log::error('Paid guest order contains deck access; no entitlements granted', ['order' => $order->order_number]);

            return;
        }

        $this->entitlements->grant($order->user_id, $deckIds, $order);
    }

    private function clearPurchasedCartItems(Order $order): void
    {
        $cartId = $order->metadata['cart_id'] ?? null;

        if ($cartId) {
            CartItem::where('cart_id', $cartId)
                ->whereIn('product_id', $order->items->pluck('product_id'))
                ->delete();
        }
    }
}
