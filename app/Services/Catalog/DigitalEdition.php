<?php

namespace App\Services\Catalog;

use App\Enums\ProductType;
use App\Models\Deck;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Str;

/**
 * A deck's digital edition is configured on the deck (price, SKU, on sale) and sold through a
 * product the deck owns: cart, checkout, orders and entitlements keep working on products,
 * while the store and the product admin never show it.
 */
class DigitalEdition
{
    public function productFor(Deck $deck): ?Product
    {
        return Product::where('product_type', ProductType::DigitalDeck->value)
            ->where('linked_deck_id', $deck->getKey())
            ->first();
    }

    /** Creates or updates the deck's digital product from the deck's digital settings. */
    public function sync(Deck $deck): ?Product
    {
        $product = $this->productFor($deck);

        // Never priced, never sold: nothing to create.
        if (! $product && $deck->digital_price_cents === null) {
            return null;
        }

        $product ??= new Product([
            'product_type' => ProductType::DigitalDeck,
            'linked_deck_id' => $deck->getKey(),
            'slug' => $this->freeSlug('dig-'.Str::lower($deck->deck_code)),
            'stock_quantity' => Product::UNLIMITED_STOCK,
        ]);

        $product->fill([
            'name' => "{$deck->title} — Digital Deck",
            'sku' => $deck->digital_sku ?: 'DIG-'.$deck->deck_code,
            'price_cents' => $deck->digital_price_cents ?? $product->price_cents,
            'requires_shipping' => false,
            'is_active' => $deck->is_active && $deck->is_digital_sale_active && $deck->digital_price_cents !== null,
        ]);
        $product->save();
        $product->decks()->sync([$deck->getKey()]);

        return $product;
    }

    /** dig-deck-001, or dig-deck-001-2 if a product or store category already uses it. */
    private function freeSlug(string $base): string
    {
        $slug = $base;
        for ($i = 2; Product::where('slug', $slug)->exists() || ProductCategory::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
