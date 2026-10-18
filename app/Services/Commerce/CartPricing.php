<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;

/** Prices a cart from current product data. Nothing price-related is trusted from the client. */
class CartPricing
{
    public function __construct(
        private readonly UpsellEngine $upsell,
        private readonly DeckEditions $editions,
    ) {}

    /**
     * Expects items.product.decks to be loaded.
     *
     * @return array{
     *     lines: list<array{item: CartItem, unit_price_cents: int, line_subtotal_cents: int, applied_discount_cents: int, digital_credit_cents: int, line_total_cents: int, available: bool}>,
     *     subtotal_cents: int, discount_cents: int, digital_credit_cents: int, tax_cents: int, shipping_cents: int, shipping_label: ?string, total_cents: int,
     *     requires_shipping: bool, currency: string
     * }
     */
    public function price(Cart $cart): array
    {
        $items = $cart->items->keyBy('id');
        $lines = [];
        // Upgrade credit needs an account (guests have no purchases to credit).
        $user = $cart->user_id ? User::find($cart->user_id) : null;
        $creditedDecks = [];

        foreach ($cart->items as $item) {
            $product = $item->product;
            $unit = $product->effectivePriceCents();
            $lineSubtotal = $unit * $item->quantity;
            $discount = 0;

            $anchor = $item->upsell_anchor_item_id ? $items->get($item->upsell_anchor_item_id) : null;
            $percent = $anchor ? $this->upsell->discountPercentFor($anchor->product, $product) : null;
            if ($percent) {
                // One discounted add-on per anchor copy.
                $discount = $this->upsell->unitDiscountCents($product, $percent) * min($item->quantity, $anchor->quantity);
            }

            // Digital-to-physical upgrade: once per deck per cart (extra boxes, e.g. gifts, are full price).
            $credit = 0;
            $deckIds = $product->decks->modelKeys();
            if ($user && array_intersect($deckIds, $creditedDecks) === []) {
                $credit = min($this->editions->creditForProduct($user, $product), $unit, $lineSubtotal - $discount);
                if ($credit > 0) {
                    $creditedDecks = [...$creditedDecks, ...$deckIds];
                }
            }
            $discount += $credit;

            $lines[] = [
                'item' => $item,
                'unit_price_cents' => $unit,
                'line_subtotal_cents' => $lineSubtotal,
                'applied_discount_cents' => $discount,
                'digital_credit_cents' => $credit,
                'line_total_cents' => $lineSubtotal - $discount,
                'available' => $product->is_active && $product->hasStockFor($item->quantity),
            ];
        }

        $subtotal = array_sum(array_column($lines, 'line_subtotal_cents'));
        $discount = array_sum(array_column($lines, 'applied_discount_cents'));
        $taxable = $subtotal - $discount;
        $requiresShipping = $cart->items->contains(fn (CartItem $i) => $i->product->requiresShipping());

        $tax = intdiv($taxable * (int) config('provoke.commerce.tax_rate_bps') + 5000, 10000);
        // Complimentary on every order: never a shipping fee.
        $shipping = 0;

        return [
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'digital_credit_cents' => array_sum(array_column($lines, 'digital_credit_cents')),
            'tax_cents' => $tax,
            'shipping_cents' => $shipping,
            'shipping_label' => $requiresShipping ? (string) config('provoke.commerce.shipping_label') : null,
            'total_cents' => $taxable + $tax + $shipping,
            'requires_shipping' => $requiresShipping,
            'currency' => (string) config('provoke.commerce.currency'),
        ];
    }
}
