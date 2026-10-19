<?php

namespace Tests\Feature\Seo;

use App\Enums\DeckSegment;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Product;
use App\Models\ProductCategory;
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
        $this->assertSame('https://provokenow.com/store/velvet-hours-box', $data['seo']['canonical_url']);
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
        $this->assertSame(['Home', 'Store', 'Velvet Hours Box Edition'], array_column($crumbs, 'name'));
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));

        // In a store category: Home > Store > Category > Product.
        $product->update(['product_category_id' => ProductCategory::create(['name' => 'Printed Game Boxes', 'slug' => 'printed-game-boxes'])->id]);
        $crumbs = $this->node($this->getJson('/api/v1/products/velvet-hours-box')->json('data.schema_json_ld'), 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['Home', 'Store', 'Printed Game Boxes', 'Velvet Hours Box Edition'], array_column($crumbs, 'name'));
        $this->assertSame('https://provokenow.com/store/printed-game-boxes', $crumbs[2]['item']);
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
            'canonical_url' => 'https://provokenow.com/store/massage-oil',
            'og_image' => 'https://cdn.provokenow.com/og/oil.jpg',
        ]);

        $long = $this->getJson('/api/v1/products/blindfold')->json('data');
        $this->assertLessThanOrEqual(60, mb_strlen($long['seo']['title']));
        $this->assertStringEndsWith('… | PROVOKE NOW', $long['seo']['title'], 'The keyword part is shortened, the brand stays last.');
        $this->assertLessThanOrEqual(155, mb_strlen($long['seo']['description']));
        $this->assertSame('https://schema.org/OutOfStock', $this->node($long['schema_json_ld'], 'Product')['offers']['availability']);

        $custom = $this->getJson('/api/v1/products/oil')->json('data.seo');
        $this->assertSame('Warming Massage Oil', $custom['title']);
        $this->assertSame('Custom description.', $custom['description']);
        $this->assertSame('https://provokenow.com/store/massage-oil', $custom['canonical_url']);
        $this->assertSame('https://cdn.provokenow.com/og/oil.jpg', $custom['og']['image']);
    }

    public function test_deck_response_includes_seo_faq_breadcrumbs_and_offers_without_leaking_paid_cards(): void
    {
        $deck = Deck::factory()->segment(DeckSegment::MediumSpice, 3)->create([
            'deck_code' => 'DECK-021',
            'title' => 'After Midnight',
            'core_theme_keywords' => 'anticipation, role play, trust',
            'faqs' => [['question' => 'Is After Midnight good for long-distance couples?', 'answer' => 'Yes, 20 cards work over video.']],
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

        $this->assertSame('After Midnight: Card Game for Couples | PROVOKE NOW', $data['seo']['title']);
        $this->assertSame('https://provokenow.com/decks/after-midnight', $data['seo']['canonical_url']);
        $this->assertStringContainsString('Medium Spice', $data['seo']['description']);

        $schema = $data['schema_json_ld'];
        $faq = $this->node($schema, 'FAQPage');
        $questions = array_column($faq['mainEntity'], 'name');
        // Its own questions only, exactly as entered.
        $this->assertSame(['Is After Midnight good for long-distance couples?'], $questions);
        $this->assertSame('Question', $faq['mainEntity'][0]['@type']);
        $this->assertSame('Answer', $faq['mainEntity'][0]['acceptedAnswer']['@type']);

        // Without its own questions, the deck gets generated ones (still never paid cards).
        $deck->update(['faqs' => null]);
        $schema = $this->getJson('/api/v1/decks/DECK-021')->json('data.schema_json_ld');
        $questions = array_column($this->node($schema, 'FAQPage')['mainEntity'], 'name');
        $this->assertContains('What is After Midnight?', $questions);
        $this->assertContains('Is After Midnight available as a physical deck?', $questions);
        $this->assertNotContains('What does a card from After Midnight look like?', $questions, 'No card text in public schema.');

        $encoded = json_encode($schema);
        $this->assertStringNotContainsString('Write a three-word invitation.', $encoded);
        $this->assertStringNotContainsString('PAID-CONTENT-MARKER', $encoded);

        $skus = collect($schema['@graph'])->where('@type', 'Product')->pluck('sku')->sort()->values()->all();
        $this->assertSame(['DIG-021', 'POD-021'], $skus);
        $crumbs = $this->node($schema, 'BreadcrumbList')['itemListElement'];
        $this->assertSame(['Home', 'Decks', 'Medium Spice', 'After Midnight'], array_column($crumbs, 'name'));
        $this->assertSame('https://provokenow.com/decks/medium-spice', $crumbs[2]['item']);

        // Digital edition: final sale, no shipping. Box: free shipping with times, 7-day returns.
        $offers = collect($schema['@graph'])->where('@type', 'Product')->keyBy('sku')->map(fn ($p) => $p['offers']);
        $this->assertArrayNotHasKey('shippingDetails', $offers['DIG-021']);
        $this->assertSame('https://schema.org/MerchantReturnNotPermitted', $offers['DIG-021']['hasMerchantReturnPolicy']['returnPolicyCategory']);
        $shipping = $offers['POD-021']['shippingDetails'];
        $this->assertSame('0', $shipping['shippingRate']['value']);
        $this->assertSame(['@type' => 'DefinedRegion', 'addressCountry' => 'US'], $shipping['shippingDestination'][0]);
        $this->assertSame([3, 5], [$shipping['deliveryTime']['handlingTime']['minValue'], $shipping['deliveryTime']['handlingTime']['maxValue']]);
        $this->assertSame([5, 10], [$shipping['deliveryTime']['transitTime']['minValue'], $shipping['deliveryTime']['transitTime']['maxValue']]);
        $this->assertSame(7, $offers['POD-021']['hasMerchantReturnPolicy']['merchantReturnDays']);
        $this->assertSame('https://schema.org/ReturnByMail', $offers['POD-021']['hasMerchantReturnPolicy']['returnMethod']);
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
            ->assertJsonPath('data.organization_schema_json_ld.@graph.0.@type', 'Organization')
            ->assertJsonPath('data.organization_schema_json_ld.@graph.0.contactPoint.email', 'support@provokenow.com')
            ->assertJsonPath('data.organization_schema_json_ld.@graph.1.@type', 'WebSite');

        $this->assertStringNotContainsString('SECRET', $response->getContent());
    }
}
