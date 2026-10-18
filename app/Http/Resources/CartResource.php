<?php

namespace App\Http\Resources;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the output of CartPricing::price() plus the cart itself.
 *
 * @property array{cart: Cart, priced: array} $resource
 */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        ['cart' => $cart, 'priced' => $priced] = $this->resource;

        return [
            'id' => $cart->id,
            // Guests must send this back as X-Cart-Token on every cart/checkout request.
            'cart_token' => $cart->isGuest() ? $cart->token : null,
            'items' => array_map(fn (array $line) => [
                'id' => $line['item']->id,
                'quantity' => $line['item']->quantity,
                'upsell_anchor_item_id' => $line['item']->upsell_anchor_item_id,
                'available' => $line['available'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_subtotal_cents' => $line['line_subtotal_cents'],
                'applied_discount_cents' => $line['applied_discount_cents'],
                // Part of applied_discount_cents: "Digital credit applied" on the invoice.
                'digital_credit_cents' => $line['digital_credit_cents'],
                'line_total_cents' => $line['line_total_cents'],
                'product' => new ProductResource($line['item']->product),
            ], $priced['lines']),
            'totals' => [
                'currency' => $priced['currency'],
                'subtotal_cents' => $priced['subtotal_cents'],
                'discount_cents' => $priced['discount_cents'],
                'digital_credit_cents' => $priced['digital_credit_cents'],
                'tax_cents' => $priced['tax_cents'],
                'shipping_cents' => $priced['shipping_cents'],
                'shipping_label' => $priced['shipping_label'],
                'total_cents' => $priced['total_cents'],
                'is_estimate' => true,
            ],
            'requires_shipping' => $priced['requires_shipping'],
        ];
    }
}
