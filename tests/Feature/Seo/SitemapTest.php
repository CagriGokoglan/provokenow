<?php

namespace Tests\Feature\Seo;

use App\Models\Deck;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Deck::factory()->create(['deck_code' => 'DECK-001', 'title' => 'First Light']);
        Deck::factory()->inactive()->create(['deck_code' => 'DECK-002', 'title' => 'Retired Deck']);
        Product::factory()->accessory()->create(['slug' => 'silk-blindfold', 'og_image' => 'https://cdn.provokenow.com/og/silk.jpg']);
        Product::factory()->inactive()->create(['slug' => 'retired-product']);
        ProductCategory::factory()->create(['slug' => 'heels']);
        ProductCategory::factory()->create(['slug' => 'hidden-category', 'is_active' => false]);

        // A misconfigured private page must still be filtered out.
        config(['provoke.seo.static_pages' => [
            ['/', 'daily', '1.0'],
            ['/decks', 'daily', '0.9'],
            ['/vault', 'daily', '0.5'],
            ['/checkout/success', 'daily', '0.5'],
            ['/user/entitlements', 'daily', '0.5'],
        ]]);
    }

    public function test_sitemap_lists_only_active_public_urls(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        $doc = simplexml_load_string($response->getContent());
        $this->assertNotFalse($doc, 'Sitemap is not well-formed XML.');
        $this->assertSame('urlset', $doc->getName());
        $this->assertContains('http://www.sitemaps.org/schemas/sitemap/0.9', $doc->getNamespaces());

        $locs = [];
        foreach ($doc->url as $url) {
            $locs[] = (string) $url->loc;
            $this->assertNotEmpty((string) $url->changefreq);
        }

        $this->assertEqualsCanonicalizing([
            'https://provokenow.com/',
            'https://provokenow.com/decks',
            'https://provokenow.com/decks/light-ignition',
            'https://provokenow.com/decks/medium-spice',
            'https://provokenow.com/decks/advanced',
            'https://provokenow.com/decks/taboo',
            'https://provokenow.com/decks/first-light',
            'https://provokenow.com/store/heels',
            'https://provokenow.com/store/silk-blindfold',
        ], $locs);

        foreach (['/vault', '/checkout', '/user', '/cart', 'retired-deck', 'retired-product', 'hidden-category', '/api/', '?level=', '?category='] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }

        $this->assertStringContainsString('<image:loc>https://cdn.provokenow.com/og/silk.jpg</image:loc>', $response->getContent());
    }

    public function test_artisan_command_writes_the_sitemap_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sitemap');

        $this->artisan('provoke:sitemap', ['--path' => $path])
            ->expectsOutputToContain('9 URLs')
            ->assertSuccessful();

        $xml = file_get_contents($path);
        unlink($path);

        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('https://provokenow.com/decks/first-light', $xml);
    }
}
