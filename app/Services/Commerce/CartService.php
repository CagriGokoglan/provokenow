<?php

namespace App\Services\Commerce;

use App\Enums\ProductType;
use App\Exceptions\BusinessRuleException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Access\EntitlementService;

class CartService
{
    public function __construct(
        private readonly UpsellEngine $upsell,
        private readonly EntitlementService $entitlements,
    ) {}

    public function add(Cart $cart, Product $product, int $quantity, ?User $user, ?string $anchorItemId = null): CartItem
    {
        $product->loadMissing('decks');

        if (! $product->is_active) {
            throw new BusinessRuleException('This product is not available.', 422);
        }

        if ($user && $product->product_type === ProductType::DigitalDeck && $this->entitlements->userOwnsAllDecksOf($user, $product)) {
            throw new BusinessRuleException('You already own this deck.', 409);
        }

        $existing = $cart->items->firstWhere('product_id', $product->id);
        $newQuantity = ($existing?->quantity ?? 0) + $quantity;

        // A digital licence is per account; more than one copy makes no sense.
        if ($product->product_type === ProductType::DigitalDeck) {
            $newQuantity = 1;
        }

        $max = (int) config('provoke.commerce.max_line_quantity');
        if ($newQuantity > $max) {
            throw new BusinessRuleException("You can add at most {$max} of this item.", 422);
        }

        if (! $product->hasStockFor($newQuantity)) {
            throw new BusinessRuleException('Not enough stock available.', 409, [
                'product_id' => ["Only {$product->stock_quantity} left in stock."],
            ]);
        }

        $item = $existing ?? new CartItem(['cart_id' => $cart->id, 'product_id' => $product->id]);
        $item->quantity = $newQuantity;
        if ($anchorItemId !== null) {
            $item->upsell_anchor_item_id = $anchorItemId;
        }
        $item->save();
        $cart->touch();

        return $item;
    }

    /**
     * Adds an upsell offer to the cart, linked to the digital-deck line that unlocks its discount.
     */
    public function applyUpsell(Cart $cart, Product $product, ?User $user, ?string $anchorItemId = null): CartItem
    {
        $offer = $this->upsell->suggestionsForCart($cart)
            ->first(fn (array $s) => $s['product']->is($product)
                && ($anchorItemId === null || $s['anchor_item_id'] === $anchorItemId));

        if (! $offer) {
            throw new BusinessRuleException('This product is not an available upsell for the current cart.', 422);
        }

        return $this->add($cart, $product, 1, $user, $offer['anchor_item_id']);
    }

    public function remove(Cart $cart, string $itemId): void
    {
        $item = $cart->items->firstWhere('id', $itemId);

        if (! $item) {
            throw new BusinessRuleException('Cart item not found.', 404);
        }

        // Add-ons anchored to this line keep their place but lose the bundle discount (FK nulls).
        $item->delete();
        $cart->touch();
    }
}
