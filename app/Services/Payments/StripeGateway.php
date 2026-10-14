<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\OrderItem;
use LogicException;
use Stripe\StripeClient;

/**
 * Stripe Checkout (hosted page). Card data never touches our servers; Stripe also collects
 * the shipping address for physical orders. The order's own totals are the source of truth:
 * line items are built so Stripe's amount_total equals orders.total_cents exactly, and the
 * webhook re-checks that before fulfilling.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly ?StripeClient $client = null) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret')) && filled(config('services.stripe.webhook_secret'));
    }

    public function createCheckout(Order $order): CheckoutRedirect
    {
        $session = $this->client()->checkout->sessions->create(
            $this->sessionParams($order),
            ['idempotency_key' => "checkout-session-{$order->getKey()}"],
        );

        return new CheckoutRedirect($session->url, $session->id);
    }

    /** @return array<string, mixed> */
    public function sessionParams(Order $order): array
    {
        $order->loadMissing('items');
        $currency = strtolower($order->currency);
        $frontend = config('provoke.frontend.url');
        $requiresShipping = (bool) ($order->metadata['requires_shipping'] ?? false);
        $reference = ['order_id' => $order->getKey(), 'order_number' => $order->order_number];

        $lineItems = $order->items->map(fn (OrderItem $item) => $this->lineItem($item, $currency))->all();

        if ($order->tax_cents > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => ['currency' => $currency, 'unit_amount' => $order->tax_cents, 'product_data' => ['name' => 'Tax']],
            ];
        }

        $charged = array_sum(array_map(fn ($l) => $l['price_data']['unit_amount'] * $l['quantity'], $lineItems))
            + ($requiresShipping ? $order->shipping_cents : 0);
        if ($charged !== $order->total_cents) {
            throw new LogicException("Stripe line items ({$charged}) do not match order total ({$order->total_cents}).");
        }

        return array_filter([
            'mode' => 'payment',
            'client_reference_id' => $order->getKey(),
            'customer_email' => $order->customer_email,
            'line_items' => $lineItems,
            'metadata' => $reference,
            'payment_intent_data' => [
                'metadata' => $reference,
                'description' => "PROVOKE NOW order {$order->order_number}",
            ],
            'billing_address_collection' => 'auto',
            'shipping_address_collection' => $requiresShipping
                ? ['allowed_countries' => config('services.stripe.shipping_countries')]
                : null,
            'shipping_options' => $requiresShipping ? [[
                'shipping_rate_data' => [
                    'type' => 'fixed_amount',
                    'display_name' => $order->shipping_cents === 0 ? 'Complimentary shipping' : 'Standard shipping',
                    'fixed_amount' => ['amount' => $order->shipping_cents, 'currency' => $currency],
                ],
            ]] : null,
            'success_url' => "{$frontend}/checkout/success?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url' => "{$frontend}/checkout/cancelled",
            'expires_at' => now()->addMinutes((int) config('services.stripe.session_ttl_minutes'))->getTimestamp(),
        ], fn ($v) => $v !== null);
    }

    /**
     * One Stripe line per order line. Bundle discounts are already applied in subtotal_cents;
     * if that does not divide evenly by quantity, the line is sent as a single "× qty" item.
     */
    private function lineItem(OrderItem $item, string $currency): array
    {
        $name = (string) ($item->metadata['name'] ?? 'Item');
        $even = $item->subtotal_cents % $item->quantity === 0;

        return [
            'quantity' => $even ? $item->quantity : 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $even ? intdiv($item->subtotal_cents, $item->quantity) : $item->subtotal_cents,
                'product_data' => [
                    'name' => $even ? $name : "{$name} × {$item->quantity}",
                    'metadata' => ['sku' => (string) ($item->metadata['sku'] ?? '')],
                ],
            ],
        ];
    }

    private function client(): StripeClient
    {
        return $this->client ?? new StripeClient((string) config('services.stripe.secret'));
    }
}
