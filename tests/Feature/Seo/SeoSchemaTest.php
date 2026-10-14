<?php

namespace Tests\Feature\Seo;

use App\Enums\DeckSegment;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeoSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $jsonLd, string $type): ?array
    {
        return collect($jsonLd['@graph'])->firstWhere('@type', $type);
    }

    public function test_product_response_includes_seo_and_product_schema(): void
    {
        $deck = Deck::factory()->create(['deck_code' => 'DECK-007']);
        $product = Product::factory()->podDeck($deck, 4500)->create([
            'name' => 'Velvet Hours Box Edition',
            'slug' => 'velvet-hours-box',
            'sku' => 'POD-007',
            'sale_price_cents' => 3900,
            'metadata' => ['images' => ['https://cdn.provokenow.com/p/velvet.jpg']],
        ]);

        $data = $this->getJson('/api/v1/products/velvet-hours-box')->assertOk()->json('data');

        $this->assertSame('Velvet Hours Box Edition | PROVOKE NOW', $data['seo']['title']);
        $this->assertLessThanOrEqual(155, mb_strlen($data['seo']['description']));
        $this->assertSame('https://provokenow.com/shop/velvet-hours-box', $data['seo']['canonical_url']);
        $this->assertSame('https://cdn.provokenow.com/p/velvet.jpg', $data['seo']['og']['image']);
        $this->assertSame('summary_large_image', $data['seo']['twitter']['card']);

        $schema = $data['schema_json_ld'];
        $this->assertSame('https://schema.org', $schema['@context']);

        $productNode = $this->node($schema, 'Product');
        $this->assertSame('POD-007', $productNode['sku']);
        $this->assertSame(['@type' => 'Brand', 'name' => 'PROVOKE NOW'], $productNode['brand']);
        $this->assertSame('39.00', $productNode['offers']['price']);
        $this->assertSame('USD', $productNode['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $productNode['offers']['availability']);

        $crumbs = $this->node($schema, 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['Home', 'Shop', 'Velvet Hours Box Edition'], array_column($crumbs, 'name'));
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));
    }

    public function test_editorial_seo_fields_override_fallbacks_and_limits_are_enforced(): void
    {
        Product::factory()->accessory([], stock: 0)->create([
            'name' => str_repeat('Exceptionally Long Silk Blindfold Name ', 4),
            'slug' => 'blindfold',
            'description' => str_repeat('Hand-finished mulberry silk. ', 20),
        ]);
        Product::factory()->accessory()->create([
            'slug' => 'oil',
            'meta_title' => 'Warming Massage Oil',
            'meta_description' => 'Custom description.',
            'canonical_url' => 'https://provokenow.com/shop/massage-oil',
            'og_image' => 'https://cdn.provokenow.com/og/oil.jpg',
        ]);

        $long = $this->getJson('/api/v1/products/blindfold')->json('data');
        $this->assertLessThanOrEqual(60, mb_strlen($long['seo']['title']));
        $this->assertStringEndsWith('…', $long['seo']['title']);
        $this->assertLessThanOrEqual(155, mb_strlen($long['seo']['description']));
        $this->assertSame('https://schema.org/OutOfStock', $this->node($long['schema_json_ld'], 'Product')['offers']['availability']);

        $custom = $this->getJson('/api/v1/products/oil')->json('data.seo');
        $this->assertSame('Warming Massage Oil', $custom['title']);
        $this->assertSame('Custom description.', $custom['description']);
        $this->assertSame('https://provokenow.com/shop/massage-oil', $custom['canonical_url']);
        $this->assertSame('https://cdn.provokenow.com/og/oil.jpg', $custom['og']['image']);
    }

    public function test_deck_response_includes_seo_faq_breadcrumbs_and_offers_without_leaking_paid_cards(): void
    {
        $deck = Deck::factory()->segment(DeckSegment::MediumSpice, 3)->create([
            'deck_code' => 'DECK-021',
            'title' => 'After Midnight',
            'core_theme_keywords' => 'anticipation, role play, trust',
            'seo_faq' => [['question' => 'Is After Midnight good for long-distance couples?', 'answer' => 'Yes, 20 cards work over video.']],
        ]);
        Card::factory()->for($deck)->create(['card_number' => 1, 'title' => 'The Invitation', 'content_payload' => [
            'Setting_and_Scenario' => 'Dim the lights.', 'Action_Task' => 'Write a three-word invitation.',
        ]]);
        Card::factory()->for($deck)->create(['card_number' => 2, 'title' => 'Secret Card', 'content_payload' => [
            'Action_Task' => 'PAID-CONTENT-MARKER',
        ]]);
        Product::factory()->digitalDeck($deck, 1900)->create(['sku' => 'DIG-021']);
        Product::factory()->podDeck($deck, 3900)->create(['sku' => 'POD-021']);

        // Even the owner's response must not put paid cards into public structured data.
        $owner = User::factory()->create();
        $owner->entitlements()->create(['deck_id' => $deck->id, 'granted_at' => now()]);
        Sanctum::actingAs($owner);

        $data = $this->getJson('/api/v1/decks/DECK-021')->assertOk()->assertJsonPath('data.is_locked', false)->json('data');

        $this->assertSame('After Midnight | PROVOKE NOW', $data['seo']['title']);
        $this->assertSame('https://provokenow.com/decks/deck-021', $data['seo']['canonical_url']);
        $this->assertStringContainsString('Medium Spice', $data['seo']['description']);

        $schema = $data['schema_json_ld'];
        $faq = $this->node($schema, 'FAQPage');
        $questions = array_column($faq['mainEntity'], 'name');
        $this->assertSame('Is After Midnight good for long-distance couples?', $questions[0]);
        $this->assertContains('What is After Midnight?', $questions);
        $this->assertContains('Is After Midnight available as a physical deck?', $questions);
        $this->assertContains('What does a card from After Midnight look like?', $questions);
        $this->assertSame('Question', $faq['mainEntity'][0]['@type']);
        $this->assertSame('Answer', $faq['mainEntity'][0]['acceptedAnswer']['@type']);

        $encoded = json_encode($schema);
        $this->assertStringContainsString('Write a three-word invitation.', $encoded);
        $this->assertStringNotContainsString('PAID-CONTENT-MARKER', $encoded);

        $skus = collect($schema['@graph'])->where('@type', 'Product')->pluck('sku')->sort()->values()->all();
        $this->assertSame(['DIG-021', 'POD-021'], $skus);
        $this->assertSame(['Home', 'Decks', 'After Midnight'], array_column($this->node($schema, 'BreadcrumbList')['itemListElement'], 'name'));
    }

    public function test_public_config_exposes_tracking_ids_but_never_secrets(): void
    {
        config([
            'provoke.tracking.meta_pixel_id' => '1234567890',
            'provoke.tracking.gtm_container_id' => 'GTM-ABC123',
            'provoke.tracking.google_site_verification' => 'gsc-token',
            'services.meta.capi_access_token' => 'SECRET-CAPI-TOKEN',
            'services.brevo.key' => 'SECRET-BREVO-KEY',
        ]);

        $response = $this->getJson('/api/v1/config/public')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tracking.meta_pixel_id', '1234567890')
            ->assertJsonPath('data.tracking.gtm_container_id', 'GTM-ABC123')
            ->assertJsonPath('data.tracking.google_site_verification', 'gsc-token')
            ->assertJsonPath('data.locale.currency', 'USD')
            ->assertJsonPath('data.locale.default', 'en')
            ->assertJsonPath('data.organization_schema_json_ld.@graph.0.@type', 'Organization');

        $this->assertStringNotContainsString('SECRET', $response->getContent());
    }
}
