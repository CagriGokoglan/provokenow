<?php

namespace App\Services\Marketing;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Tracking\TrackingContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta Conversions API (server-side events).
 *
 * - Only built when the visitor consented (TrackingContext::$consent).
 * - Customer identifiers (em, ph, fn, ln, ct, st, zp, country, external_id) are normalised
 *   and SHA-256 hashed *before* the event is queued, so no raw PII sits in the jobs table.
 * - client_ip_address, client_user_agent, fbp and fbc are sent unhashed, as Meta requires.
 * - Products are identified by SKU only; names are never sent.
 * - event_id is shared with the browser Pixel so Meta de-duplicates the two.
 */
class MetaCapiService
{
    public function isEnabled(): bool
    {
        return filled(config('services.meta.pixel_id')) && filled(config('services.meta.capi_access_token'));
    }

    public function addToCartEvent(Product $product, int $quantity, string $eventId, ?User $user, TrackingContext $ctx): ?array
    {
        $price = $product->effectivePriceCents();

        return $this->event('AddToCart', $eventId, $ctx, $this->userData($ctx, email: $user?->email, externalId: $user?->getKey()), [
            'currency' => config('provoke.commerce.currency'),
            'value' => $this->money($price * $quantity),
            'content_type' => 'product',
            'content_ids' => [$product->sku],
            'contents' => [['id' => $product->sku, 'quantity' => $quantity, 'item_price' => $this->money($price)]],
        ]);
    }

    public function initiateCheckoutEvent(Order $order, TrackingContext $ctx): ?array
    {
        return $this->orderEvent('InitiateCheckout', "checkout.{$order->getKey()}", $order, $ctx);
    }

    public function purchaseEvent(Order $order): ?array
    {
        return $this->orderEvent(
            'Purchase',
            "purchase.{$order->getKey()}",
            $order,
            TrackingContext::fromArray($order->metadata['tracking'] ?? null),
            ['order_id' => $order->order_number],
        );
    }

    /** POST one event. Called from the queued job. */
    public function send(array $event): Response
    {
        $payload = array_filter([
            'data' => [$event],
            'test_event_code' => config('services.meta.test_event_code'),
        ]);

        return Http::baseUrl('https://graph.facebook.com')
            ->acceptJson()
            ->timeout(10)
            ->withQueryParameters(['access_token' => config('services.meta.capi_access_token')])
            ->post(sprintf('/%s/%s/events', config('services.meta.graph_version'), config('services.meta.pixel_id')), $payload)
            ->throw();
    }

    private function orderEvent(string $name, string $eventId, Order $order, TrackingContext $ctx, array $extra = []): ?array
    {
        $order->loadMissing('items.product');
        $address = (array) ($order->billing_address ?: $order->shipping_address);
        [$first, $last] = array_pad(explode(' ', trim((string) ($address['name'] ?? '')), 2), 2, null);

        $userData = $this->userData(
            $ctx,
            email: $order->customer_email,
            phone: $address['phone'] ?? null,
            externalId: $order->user_id,
            firstName: $first,
            lastName: $last,
            city: $address['city'] ?? null,
            state: $address['state'] ?? null,
            zip: $address['postal_code'] ?? null,
            country: $address['country'] ?? null,
        );

        return $this->event($name, $eventId, $ctx, $userData, [
            'currency' => $order->currency,
            'value' => $this->money($order->total_cents),
            'content_type' => 'product',
            'content_ids' => $order->items->map(fn ($i) => $i->product->sku)->values()->all(),
            'contents' => $order->items->map(fn ($i) => [
                'id' => $i->product->sku,
                'quantity' => $i->quantity,
                'item_price' => $this->money($i->unit_price_cents),
            ])->values()->all(),
            'num_items' => (int) $order->items->sum('quantity'),
            ...$extra,
        ]);
    }

    private function event(string $name, string $eventId, TrackingContext $ctx, array $userData, array $customData): ?array
    {
        if (! $ctx->consent || ! $this->isEnabled()) {
            return null;
        }

        return array_filter([
            'event_name' => $name,
            'event_time' => now()->getTimestamp(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $ctx->sourceUrl,
            'user_data' => $userData,
            'custom_data' => $customData,
        ], fn ($v) => $v !== null);
    }

    private function userData(
        TrackingContext $ctx,
        ?string $email = null,
        ?string $phone = null,
        ?string $externalId = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $city = null,
        ?string $state = null,
        ?string $zip = null,
        ?string $country = null,
    ): array {
        $hashed = [
            'em' => $this->hash($email, fn ($v) => strtolower(trim($v))),
            'ph' => $this->hash($phone, fn ($v) => preg_replace('/\D+/', '', $v)),
            'external_id' => $this->hash($externalId, fn ($v) => strtolower(trim($v))),
            'fn' => $this->hash($firstName, fn ($v) => $this->letters($v)),
            'ln' => $this->hash($lastName, fn ($v) => $this->letters($v)),
            'ct' => $this->hash($city, fn ($v) => $this->letters($v)),
            'st' => $this->hash($state, fn ($v) => $this->letters($v)),
            'zp' => $this->hash($zip, fn ($v) => strtolower(preg_replace('/\s+/', '', $v))),
            'country' => $this->hash($country, fn ($v) => strtolower(trim($v))),
        ];

        return array_filter([
            ...array_map(fn ($h) => $h ? [$h] : null, $hashed),
            'client_ip_address' => $ctx->ip,
            'client_user_agent' => $ctx->userAgent,
            'fbp' => $ctx->fbp,
            'fbc' => $ctx->fbc,
        ]);
    }

    /** @param callable(string): string $normalise */
    private function hash(?string $value, callable $normalise): ?string
    {
        $normalised = $value === null ? '' : $normalise($value);

        return $normalised === '' ? null : hash('sha256', $normalised);
    }

    private function letters(string $value): string
    {
        return preg_replace('/[^\p{L}]+/u', '', mb_strtolower(trim($value)));
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
