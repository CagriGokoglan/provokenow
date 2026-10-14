<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\Commerce\OrderFulfillmentService;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Applies verified Stripe events to orders. Every branch is idempotent, because Stripe delivers
 * at least once and may retry or reorder events.
 */
class StripeWebhookHandler
{
    public function __construct(private readonly OrderFulfillmentService $fulfillment) {}

    public function handle(Event $event): void
    {
        $object = $event->data->object->toArray();

        match ($event->type) {
            'checkout.session.completed' => in_array($object['payment_status'] ?? null, ['paid', 'no_payment_required'], true)
                ? $this->fulfil($object)
                : null, // delayed methods (bank debits) settle via async_payment_succeeded
            'checkout.session.async_payment_succeeded' => $this->fulfil($object),
            'checkout.session.async_payment_failed' => $this->fail($object, 'async_payment_failed'),
            'checkout.session.expired' => $this->fail($object, 'checkout_expired'),
            'charge.refunded' => $this->refund($object),
            default => null,
        };
    }

    private function fulfil(array $session): void
    {
        $order = $this->orderFor($session);
        if (! $order || $order->isPaid()) {
            return;
        }

        // Never trust that the customer paid what we expect: compare with our own totals.
        $paid = (int) ($session['amount_total'] ?? -1);
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        if ($paid !== $order->total_cents || $currency !== strtoupper($order->currency)) {
            Log::critical('Stripe amount mismatch; order not fulfilled', [
                'order' => $order->order_number, 'expected' => $order->total_cents, 'paid' => $paid, 'currency' => $currency,
            ]);
            $order->update(['metadata' => [...($order->metadata ?? []), 'payment_review' => [
                'reason' => 'amount_mismatch', 'paid_cents' => $paid, 'currency' => $currency, 'session' => $session['id'] ?? null,
            ]]]);

            return;
        }

        $customer = $session['customer_details'] ?? [];
        $shipping = $session['collected_information']['shipping_details'] ?? $session['shipping_details'] ?? null;

        $order->update(array_filter([
            'customer_email' => $order->customer_email ?? ($customer['email'] ?? null),
            'shipping_address' => $shipping ? $this->address($shipping['name'] ?? null, $shipping['address'] ?? [], $customer['phone'] ?? null) : null,
            'billing_address' => ! empty($customer['address']) ? $this->address($customer['name'] ?? null, $customer['address']) : null,
        ]));

        $this->fulfillment->markPaid($order, 'stripe', (string) ($session['payment_intent'] ?? $session['id']));
    }

    private function fail(array $session, string $reason): void
    {
        if ($order = $this->orderFor($session)) {
            $this->fulfillment->markFailed($order, $reason);
        }
    }

    private function refund(array $charge): void
    {
        $order = isset($charge['payment_intent'])
            ? Order::where('payment_gateway', 'stripe')->where('transaction_id', $charge['payment_intent'])->first()
            : null;

        if ($order) {
            $this->fulfillment->recordRefund($order, (int) ($charge['amount_refunded'] ?? 0), (bool) ($charge['refunded'] ?? false));
        }
    }

    private function orderFor(array $session): ?Order
    {
        $orderId = $session['metadata']['order_id'] ?? $session['client_reference_id'] ?? null;
        $order = is_string($orderId) ? Order::find($orderId) : null;

        // The session must be the one we created for this order.
        if (! $order || ($order->metadata['stripe_session_id'] ?? null) !== ($session['id'] ?? null)) {
            Log::warning('Stripe session does not match a known order', ['session' => $session['id'] ?? null, 'order_id' => $orderId]);

            return null;
        }

        return $order;
    }

    /** Stripe address → the order address shape used elsewhere (and by the admin panel). */
    private function address(?string $name, array $address, ?string $phone = null): array
    {
        return array_filter([
            'name' => $name,
            'line1' => $address['line1'] ?? null,
            'line2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'country' => $address['country'] ?? null,
            'phone' => $phone,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
