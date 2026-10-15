<?php

namespace Tests\Feature\Commerce;

use App\Enums\DeckSegment;
use App\Models\Deck;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpsellTest extends TestCase
{
    use RefreshDatabase;

    private Product $digital;

    private Product $pod;

    private Product $blindfold;

    private Product $massageOil;

    private Product $unrelatedAccessory;

    protected function setUp(): void
    {
        parent::setUp();

        $deck = Deck::factory()->segment(DeckSegment::MediumSpice, 2)->create(['deck_code' => 'DECK-020']);
        $otherDeck = Deck::factory()->segment(DeckSegment::LightIgnition)->create(['deck_code' => 'DECK-001']);

        $this->digital = Product::factory()->digitalDeck($deck, 2000)->create(['slug' => 'deck-020-digital']);
        $this->pod = Product::factory()->podDeck($deck, 4000)->create(['name' => 'DECK-020 Box']);
        $this->blindfold = Product::factory()->accessory(['segments' => ['Medium_Spice']], 3000)->create(['name' => 'Silk Blindfold']);
        $this->massageOil = Product::factory()->accessory(['deck_codes' => ['DECK-020']], 2400)->create(['name' => 'Massage Oil']);

        // Must never be suggested for DECK-020:
        $this->unrelatedAccessory = Product::factory()->accessory(['segments' => ['Taboo']])->create();
        Product::factory()->podDeck($otherDeck)->create();
        Product::factory()->accessory(['segments' => ['Medium_Spice']])->inactive()->create();
        Product::factory()->accessory(['segments' => ['Medium_Spice']], stock: 0)->create();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_upsell_rule_matches_pod_box_and_physical_accessories_for_digital_deck(): void
    {
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id])->assertOk();

        $offers = $this->postJson('/api/v1/cart/apply-upsell')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.upsell_offers');

        $byId = collect($offers)->keyBy('product.id');
        $this->assertEqualsCanonicalizing(
            [$this->pod->id, $this->blindfold->id, $this->massageOil->id],
            $byId->keys()->all(),
        );

        $this->assertSame('matching_pod_box', $byId[$this->pod->id]['reason']);
        $this->assertSame('paired_accessory', $byId[$this->blindfold->id]['reason']);
        $this->assertTrue($byId[$this->blindfold->id]['bundle_discount_eligible']);
        $this->assertSame(15, $byId[$this->blindfold->id]['discount_percent']);
        $this->assertSame(450, $byId[$this->blindfold->id]['applied_discount_cents']);
        $this->assertSame(600, $byId[$this->pod->id]['applied_discount_cents']);
    }

    public function test_applying_upsell_adds_item_with_15_percent_bundle_discount(): void
    {
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id]);

        $cart = $this->postJson('/api/v1/cart/apply-upsell', ['product_id' => $this->blindfold->id])
            ->assertOk()
            ->json('data.cart');

        $line = collect($cart['items'])->firstWhere('product.id', $this->blindfold->id);
        $this->assertSame(450, $line['applied_discount_cents']);
        $this->assertSame(2550, $line['line_total_cents']);
        $this->assertSame(5000, $cart['totals']['subtotal_cents']);
        $this->assertSame(450, $cart['totals']['discount_cents']);
        $this->assertTrue($cart['requires_shipping']);

        // The anchor deck leaves the cart → the discount goes with it.
        $anchorId = collect($cart['items'])->firstWhere('product.id', $this->digital->id)['id'];
        $after = $this->deleteJson("/api/v1/cart/items/{$anchorId}")->assertOk()->json('data.cart');
        $this->assertSame(0, $after['totals']['discount_cents']);
        $this->assertSame(3000, $after['items'][0]['line_total_cents']);
    }

    public function test_discount_is_not_applied_to_products_added_normally_or_ineligible_upsells(): void
    {
        $this->postJson('/api/v1/cart/add', ['product_id' => $this->digital->id]);

        $this->postJson('/api/v1/cart/apply-upsell', ['product_id' => $this->unrelatedAccessory->id])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $cart = $this->postJson('/api/v1/cart/add', ['product_id' => $this->massageOil->id])->json('data.cart');
        $this->assertSame(0, $cart['totals']['discount_cents']);
    }

    public function test_product_page_returns_bundle_recommendations(): void
    {
        $this->getJson('/api/v1/products/deck-020-digital')
            ->assertOk()
            ->assertJsonPath('data.product.deck_claims.0.deck_code', 'DECK-020')
            ->assertJsonCount(3, 'data.recommendations')
            ->assertJsonPath('data.recommendations.0.bundle_discount_eligible', true);
    }

    public function test_product_index_lists_active_products_with_deck_claims(): void
    {
        $this->getJson('/api/v1/products?product_type=digital_deck')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.deck_claim_ids.0', $this->digital->decks()->first()->id)
            ->assertJsonPath('data.items.0.effective_price_cents', 2000);

        // 7 created, 1 inactive.
        $this->getJson('/api/v1/products')->assertJsonPath('data.pagination.total', 7);
    }
}
