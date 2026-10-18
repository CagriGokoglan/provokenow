<?php

namespace Tests\Feature\Catalog;

use App\Enums\DeckSegment;
use App\Enums\ProductType;
use App\Models\Deck;
use App\Models\DeckCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxonomyFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_levels_and_admin_categories_are_exposed_in_order(): void
    {
        $this->seed(TaxonomySeeder::class);
        DeckCategory::where('slug', 'sensory')->update(['is_active' => false]);

        $data = $this->getJson('/api/v1/taxonomies')->assertOk()->json('data');

        $this->assertSame(['light-ignition', 'medium-spice', 'advanced', 'taboo'], array_column($data['deck_levels'], 'slug'));
        $this->assertSame(['Light Ignition', 'Medium Spice', 'Advanced', 'Taboo'], array_column($data['deck_levels'], 'label'));
        $this->assertSame(['bdsm', 'roleplay', 'dominant-submissive'], array_column($data['deck_categories'], 'slug'));
        $this->assertSame(array_keys(TaxonomySeeder::PRODUCT_CATEGORIES), array_column($data['product_categories'], 'slug'));
    }

    public function test_taxonomy_seeder_never_overwrites_admin_edits(): void
    {
        $this->seed(TaxonomySeeder::class);
        ProductCategory::where('slug', 'heels')->update(['name' => 'Heels & Boots']);

        $this->seed(TaxonomySeeder::class);

        $this->assertSame('Heels & Boots', ProductCategory::firstWhere('slug', 'heels')->name);
        $this->assertSame(count(TaxonomySeeder::PRODUCT_CATEGORIES), ProductCategory::count());
    }

    public function test_decks_filter_by_level_category_and_both(): void
    {
        $roleplay = DeckCategory::factory()->create(['name' => 'Roleplay', 'slug' => 'roleplay']);
        $bdsm = DeckCategory::factory()->create(['name' => 'BDSM', 'slug' => 'bdsm']);
        $hidden = DeckCategory::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);

        Deck::factory()->segment(DeckSegment::LightIgnition, 1)->create(['deck_code' => 'DECK-001'])->categories()->attach($roleplay);
        Deck::factory()->segment(DeckSegment::Taboo, 4)->create(['deck_code' => 'DECK-002'])->categories()->attach([$roleplay->id, $bdsm->id]);
        Deck::factory()->segment(DeckSegment::Taboo, 4)->create(['deck_code' => 'DECK-003'])->categories()->attach($hidden);

        $codes = fn (string $query) => collect($this->getJson("/api/v1/decks?{$query}")->assertOk()->json('data.items'))->pluck('deck_code')->all();

        $this->assertSame(['DECK-001', 'DECK-002', 'DECK-003'], $codes(''));
        $this->assertSame(['DECK-002', 'DECK-003'], $codes('level=taboo'));
        $this->assertSame(['DECK-001', 'DECK-002'], $codes('category=roleplay'));
        $this->assertSame(['DECK-002'], $codes('level=taboo&category=roleplay'));
        $this->assertSame([], $codes('category=hidden'), 'Hidden categories cannot be used to filter.');
        $this->getJson('/api/v1/decks?level=extreme')->assertUnprocessable();

        $deck = collect($this->getJson('/api/v1/decks')->json('data.items'))->firstWhere('deck_code', 'DECK-002');
        $this->assertSame(['slug' => 'taboo', 'label' => 'Taboo', 'rank' => 4], $deck['level']);
        $this->assertEqualsCanonicalizing(['roleplay', 'bdsm'], array_column($deck['categories'], 'slug'));
        $this->assertSame([], collect($this->getJson('/api/v1/decks')->json('data.items'))->firstWhere('deck_code', 'DECK-003')['categories']);
    }

    public function test_store_filters_by_category_kind_and_merchandising_flags(): void
    {
        $heels = ProductCategory::factory()->create(['name' => 'Heels', 'slug' => 'heels']);
        $deck = Deck::factory()->create();

        $stiletto = Product::factory()->accessory()->create(['name' => 'Stiletto', 'product_category_id' => $heels->id, 'is_featured' => true]);
        $box = Product::factory()->podDeck($deck)->create(['name' => 'Box', 'is_boxed_game' => true, 'is_new_arrival' => true]);
        $digital = Product::factory()->digitalDeck($deck)->create(['name' => 'Digital']);
        Product::factory()->accessory()->inactive()->create(['product_category_id' => $heels->id]);

        $names = fn (string $query) => collect($this->getJson("/api/v1/products?{$query}")->assertOk()->json('data.items'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Stiletto'], $names('category=heels'));
        $this->assertSame(['Box'], $names('kind=games'), 'Digital editions are sold on the deck page, not in the store.');
        $this->assertSame(['Stiletto'], $names('kind=lifestyle'));
        $this->assertSame(['Box'], $names('boxed=1'));
        $this->assertSame(['Stiletto'], $names('featured=1'));
        $this->assertSame(['Box'], $names('new_arrival=1'));

        $item = collect($this->getJson('/api/v1/products?category=heels')->json('data.items'))->first();
        $this->assertSame(['name' => 'Heels', 'slug' => 'heels'], $item['category']);
        $this->assertTrue($item['is_featured']);
        $this->assertFalse($item['is_boxed_game']);
        $this->assertSame(ProductType::PodPhysicalDeck->value, collect($this->getJson('/api/v1/products?boxed=1')->json('data.items'))->first()['product_type']);
    }

    public function test_split_migration_moves_advanced_taboo_decks_and_pairing_rules_to_advanced(): void
    {
        $migration = require database_path('migrations/2026_10_16_000001_split_advanced_taboo_into_two_levels.php');
        $migration->down(); // back to the three-level schema

        $deckId = (string) Str::uuid7();
        DB::table('decks')->insert(['id' => $deckId, 'deck_code' => 'DECK-099', 'title' => 'Old', 'segment' => 'Advanced_Taboo', 'intensity_level' => 4, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::factory()->accessory(['segments' => ['Medium_Spice', 'Advanced_Taboo']])->create();

        $migration->up();

        $this->assertSame('Advanced', DB::table('decks')->where('id', $deckId)->value('segment'));
        $this->assertSame(['Medium_Spice', 'Advanced'], $product->fresh()->metadata['upsell']['segments']);
        $this->expectException(QueryException::class);
        DB::table('decks')->where('id', $deckId)->update(['segment' => 'Advanced_Taboo']);
    }
}
