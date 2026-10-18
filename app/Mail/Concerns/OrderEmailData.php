<?php

namespace App\Mail\Concerns;

use App\Models\Order;
use App\Models\SiteSetting;
use Illuminate\Mail\Mailables\Address;

/** Shared, display-ready order data for the order emails. */
trait OrderEmailData
{
    protected function brandSender(): Address
    {
        return new Address(config('services.brevo.sender.email'), config('services.brevo.sender.name'));
    }

    /** @return list<Address> */
    protected function brandReplyTo(): array
    {
        return [new Address(config('services.brevo.reply_to'))];
    }

    protected function money(Order $order, int $cents): string
    {
        return number_format($cents / 100, 2).' '.$order->currency;
    }

    /** @return array<string, mixed> */
    protected function orderData(Order $order): array
    {
        $order->loadMissing('items.product', 'user');

        return [
            'order_number' => $order->order_number,
            'customer_name' => $order->shipping_address['name'] ?? $order->user?->name,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->metadata['name'] ?? 'Item',
                'quantity' => $item->quantity,
                'total' => $this->money($order, $item->subtotal_cents),
                // Digital decks are never in the parcel.
                'ships' => $item->product === null || $item->product->requiresShipping(),
            ])->values()->all(),
            'subtotal' => $this->money($order, $order->subtotal_cents),
            'discount' => $order->discount_cents > 0 ? $this->money($order, $order->discount_cents) : null,
            'shipping' => $order->shipping_cents === 0 ? 'Complimentary' : $this->money($order, $order->shipping_cents),
            'shipping_badge' => SiteSetting::value('discreet_shipping_badge_text') ?: config('provoke.commerce.shipping_badge_text'),
            'tax' => $this->money($order, $order->tax_cents),
            'total' => $this->money($order, $order->total_cents),
            'requires_shipping' => (bool) ($order->metadata['requires_shipping'] ?? false),
            'address_lines' => $this->addressLines($order->shipping_address),
            'support_email' => config('services.brevo.reply_to'),
        ];
    }

    /** @return list<string> Name, street, "city postcode", country — whatever the address has. */
    protected function addressLines(?array $address): array
    {
        if (! $address) {
            return [];
        }

        return array_values(array_filter([
            $address['name'] ?? null,
            $address['line1'] ?? $address['address_line1'] ?? null,
            $address['line2'] ?? $address['address_line2'] ?? null,
            trim(implode(' ', array_filter([$address['postal_code'] ?? null, $address['city'] ?? null, $address['state'] ?? null]))),
            $address['country'] ?? null,
        ]));
    }
}
