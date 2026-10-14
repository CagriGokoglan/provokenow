<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\CartItem;

/** Prices a cart from current product data. Nothing price-related is trusted from the client. */
class CartPricing
{
    public function __construct(private readonly UpsellEngine $upsell) {}

    /**
     * Expects items.product.decks to be loaded.
     *
     * @return array{
     *     lines: list<array{item: CartItem, unit_price_cents: int, line_subtotal_cents: int, applied_discount_cents: int, line_total_cents: int, available: bool}>,
     *     subtotal_cents: int, discount_cents: int, tax_cents: int, shipping_cents: int, total_cents: int,
     *     requires_shipping: bool, currency: string
     * }
     */
    public function price(Cart $cart): array
    {
        $items = $cart->items->keyBy('id');
        $lines = [];

        foreach ($cart->items as $item) {
            $product = $item->product;
            $unit = $product->effectivePriceCents();
            $lineSubtotal = $unit * $item->quantity;
            $discount = 0;

            $anchor = $item->upsell_anchor_item_id ? $items->get($item->upsell_anchor_item_id) : null;
            if ($anchor && $this->upsell->isBundleEligible($anchor->product, $product)) {
                // One discounted add-on per anchor deck copy.
                $discount = $this->upsell->unitDiscountCents($product) * min($item->quantity, $anchor->quantity);
            }

            $lines[] = [
                'item' => $item,
                'unit_price_cents' => $unit,
                'line_subtotal_cents' => $lineSubtotal,
                'applied_discount_cents' => $discount,
                'line_total_cents' => $lineSubtotal - $discount,
                'available' => $product->is_active && $product->hasStockFor($item->quantity),
            ];
        }

        $subtotal = array_sum(array_column($lines, 'line_subtotal_cents'));
        $discount = array_sum(array_column($lines, 'applied_discount_cents'));
        $taxable = $subtotal - $discount;
        $requiresShipping = $cart->items->contains(fn (CartItem $i) => $i->product->requiresShipping());

        $tax = intdiv($taxable * (int) config('provoke.commerce.tax_rate_bps') + 5000, 10000);
        $shipping = $requiresShipping && $taxable < (int) config('provoke.commerce.free_shipping_threshold_cents')
            ? (int) config('provoke.commerce.shipping_flat_cents')
            : 0;

        return [
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'tax_cents' => $tax,
            'shipping_cents' => $shipping,
            'total_cents' => $taxable + $tax + $shipping,
            'requires_shipping' => $requiresShipping,
            'currency' => (string) config('provoke.commerce.currency'),
        ];
    }
}
