<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Deck;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'sku' => strtoupper(Str::random(10)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'product_type' => ProductType::DigitalDeck,
            'price_cents' => 2000,
            'sale_price_cents' => null,
            'stock_quantity' => Product::UNLIMITED_STOCK,
            'is_active' => true,
            'description' => fake()->sentence(),
            'metadata' => ['images' => ['https://cdn.provokenow.com/placeholder.jpg']],
        ];
    }

    public function digitalDeck(Deck $deck, int $priceCents = 2000): static
    {
        return $this->state(['product_type' => ProductType::DigitalDeck, 'requires_shipping' => false, 'linked_deck_id' => $deck->getKey(), 'price_cents' => $priceCents])
            ->hasAttached($deck, [], 'decks');
    }

    public function podDeck(Deck $deck, int $priceCents = 4000): static
    {
        // Saving a physical boxed game links its deck (Product::booted).
        return $this->state(['product_type' => ProductType::PodPhysicalDeck, 'linked_deck_id' => $deck->getKey(), 'price_cents' => $priceCents]);
    }

    /** @param array<string, mixed> $upsell metadata.upsell targeting rules */
    public function accessory(array $upsell = [], int $priceCents = 3000, int $stock = 25): static
    {
        return $this->state([
            'product_type' => ProductType::PhysicalAccessory,
            'price_cents' => $priceCents,
            'stock_quantity' => $stock,
            'metadata' => ['images' => [], 'upsell' => $upsell],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
