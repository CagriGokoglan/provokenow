<?php

namespace Tests\Feature\Catalog;

use App\Enums\PaymentStatus;
use App\Models\Deck;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_decks_sort_by_bestsellers_newest_and_price(): void
    {
        $a = Deck::factory()->create(['deck_code' => 'DECK-001', 'created_at' => now()->subDays(3)]);
        $b = Deck::factory()->create(['deck_code' => 'DECK-002', 'created_at' => now()->subDay()]);
        $c = Deck::factory()->create(['deck_code' => 'DECK-003', 'created_at' => now()->subDays(2)]);
        Product::factory()->digitalDeck($a, 2900)->create();
        Product::factory()->digitalDeck($b, 1500)->create(['sale_price_cents' => 900]);
        // DECK-003 has no product yet: priced decks first, it sorts last either way.

        // Sales: C sold twice, A once, B never.
        $order = Order::create(['total_cents' => 1, 'payment_status' => PaymentStatus::Paid]);
        foreach ([$c, $c, $a] as $deck) {
            User::factory()->create()->entitlements()->create(['deck_id' => $deck->id, 'order_id' => $order->id, 'granted_at' => now()]);
        }
        // A manual (non-order) grant doesn't count as a sale.
        User::factory()->create()->entitlements()->create(['deck_id' => $b->id, 'granted_at' => now()]);

        $codes = fn (string $sort) => collect($this->getJson("/api/v1/decks?sort={$sort}")->assertOk()->json('data.items'))->pluck('deck_code')->all();

        $this->assertSame(['DECK-001', 'DECK-002', 'DECK-003'], $codes('featured'));
        $this->assertSame(['DECK-003', 'DECK-001', 'DECK-002'], $codes('bestsellers'));
        $this->assertSame(['DECK-002', 'DECK-003', 'DECK-001'], $codes('newest'));
        $this->assertSame(['DECK-002', 'DECK-001', 'DECK-003'], $codes('price_asc'));
        $this->assertSame(['DECK-001', 'DECK-002', 'DECK-003'], $codes('price_desc'));
        $this->getJson('/api/v1/decks?sort=cheapest')->assertUnprocessable();

        $prices = collect($this->getJson('/api/v1/decks')->json('data.items'))->pluck('from_price_cents', 'deck_code')->all();
        $this->assertSame(['DECK-001' => 2900, 'DECK-002' => 900, 'DECK-003' => null], $prices, 'Sale price counts; no product means no price.');
    }

    public function test_products_sort_by_bestsellers_newest_and_price(): void
    {
        $cat = ProductCategory::factory()->create(['slug' => 'heels']);
        $cheap = Product::factory()->accessory([], 1000)->create(['name' => 'Cheap', 'product_category_id' => $cat->id, 'created_at' => now()->subDays(2)]);
        $sale = Product::factory()->accessory([], 5000)->create(['name' => 'Sale', 'sale_price_cents' => 1500, 'product_category_id' => $cat->id, 'created_at' => now()->subDay()]);
        $pricey = Product::factory()->accessory([], 9000)->create(['name' => 'Pricey', 'product_category_id' => $cat->id, 'created_at' => now()->subDays(3)]);

        $paid = Order::create(['total_cents' => 1, 'payment_status' => PaymentStatus::Paid]);
        $pending = Order::create(['total_cents' => 1, 'payment_status' => PaymentStatus::Pending]);
        $paid->items()->create(['product_id' => $pricey->id, 'quantity' => 3, 'unit_price_cents' => 1, 'subtotal_cents' => 3]);
        $paid->items()->create(['product_id' => $cheap->id, 'quantity' => 1, 'unit_price_cents' => 1, 'subtotal_cents' => 1]);
        $pending->items()->create(['product_id' => $sale->id, 'quantity' => 9, 'unit_price_cents' => 1, 'subtotal_cents' => 9]); // unpaid: not a sale

        $names = fn (string $sort) => collect($this->getJson("/api/v1/products?category=heels&sort={$sort}")->assertOk()->json('data.items'))->pluck('name')->all();

        $this->assertSame(['Pricey', 'Cheap', 'Sale'], $names('bestsellers'));
        $this->assertSame(['Sale', 'Cheap', 'Pricey'], $names('newest'));
        $this->assertSame(['Cheap', 'Sale', 'Pricey'], $names('price_asc'));
        $this->assertSame(['Pricey', 'Sale', 'Cheap'], $names('price_desc'));
    }

    public function test_footer_lists_only_categories_chosen_in_admin(): void
    {
        ProductCategory::factory()->create(['name' => 'Heels', 'slug' => 'heels', 'show_in_footer' => true, 'sort_order' => 2]);
        ProductCategory::factory()->create(['name' => 'Dice', 'slug' => 'dice', 'show_in_footer' => true, 'sort_order' => 1]);
        ProductCategory::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'show_in_footer' => true, 'is_active' => false]);
        ProductCategory::factory()->create(['name' => 'Not chosen', 'slug' => 'not-chosen']);

        $this->getJson('/api/v1/config/public')
            ->assertJsonPath('data.footer_categories', [['name' => 'Dice', 'slug' => 'dice'], ['name' => 'Heels', 'slug' => 'heels']]);
    }
}
