<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'payment_status' => $this->payment_status->value,
            'fulfillment_status' => $this->fulfillment_status->value,
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'tax_cents' => $this->tax_cents,
            'shipping_cents' => $this->shipping_cents,
            'total_cents' => $this->total_cents,
            'customer_email' => $this->customer_email,
            'shipping_address' => $this->shipping_address,
            'billing_address' => $this->billing_address,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->metadata['name'] ?? null,
                'product_type' => $item->metadata['product_type'] ?? null,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'applied_discount_cents' => $item->metadata['applied_discount_cents'] ?? 0,
                'subtotal_cents' => $item->subtotal_cents,
            ])->values()),
            'entitlements' => EntitlementResource::collection($this->whenLoaded('entitlements')),
        ];
    }
}
