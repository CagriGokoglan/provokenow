<?php

namespace Tests\Feature\Seo;

use App\Models\Deck;
use App\Models\Post;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Services\Blog\ArticleRenderer;
use App\Services\Seo\Faqs;
use App\Services\Seo\SeoMetadataService;
use Database\Seeders\BlogSeeder;
use Database\Seeders\DefaultFaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogAndFaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_use_their_own_faqs_or_the_admin_defaults_on_page_and_in_schema(): void
    {
        $this->seed(DefaultFaqSeeder::class);
        $plain = Product::factory()->accessory()->create(['slug' => 'silk-blindfold']);
        $custom = Product::factory()->accessory()->create(['slug' => 'velvet-cuffs', 'faqs' => [['question' => 'Are they adjustable?', 'answer' => 'Yes, five settings.']]]);

        $defaults = $this->getJson('/api/v1/products/silk-blindfold')->assertOk();
        $this->assertSame('How discreet is the packaging?', $defaults->json('data.faqs.0.question'));
        $faqNode = collect($defaults->json('data.schema_json_ld.@graph'))->firstWhere('@type', 'FAQPage');
        $this->assertCount(count(DefaultFaqSeeder::PRODUCT), $faqNode['mainEntity']);

        $own = $this->getJson('/api/v1/products/velvet-cuffs')->json('data');
        $this->assertSame([['question' => 'Are they adjustable?', 'answer' => 'Yes, five settings.']], $own['faqs']);

        $offer = collect($own['schema_json_ld']['@graph'])->firstWhere('@type', 'Product')['offers'];
        $this->assertSame('0', $offer['shippingDetails']['shippingRate']['value'], 'Complimentary shipping in the Product schema.');
        $this->assertNotNull($plain);
    }

    public function test_decks_show_their_faqs_and_admin_defaults_are_seeded_once(): void
    {
        $this->seed(DefaultFaqSeeder::class);
        SiteSetting::put(Faqs::DECK_DEFAULTS, [['question' => 'Edited?', 'answer' => 'Yes.']]);
        $this->seed(DefaultFaqSeeder::class); // never overwrites admin edits

        Deck::factory()->create(['deck_code' => 'DECK-001']);
        $faqs = $this->getJson('/api/v1/decks/deck-001')->assertOk()->json('data.faqs');

        $this->assertSame('Edited?', $faqs[0]['question']);
        $this->assertContains('What is '.Deck::first()->title.'?', array_column($faqs, 'question'));
    }

    public function test_the_pillar_post_renders_with_jump_links_ctas_author_and_schema(): void
    {
        Deck::factory()->create(['deck_code' => 'DECK-001', 'digital_price_cents' => 1299]);
        $this->seed(BlogSeeder::class);
        $this->seed(BlogSeeder::class);
        $this->assertSame(1, Post::count(), 'Seeded once.');

        $this->getJson('/api/v1/blog/posts')->assertJsonPath('data.items.0.slug', BlogSeeder::PILLAR_SLUG);
        $data = $this->getJson('/api/v1/blog/posts/'.BlogSeeder::PILLAR_SLUG)->assertOk()->json('data');

        $this->assertCount(5, $data['post']['toc']);
        $this->assertStringStartsWith('<h2 id="how-to-use-these-questions">', $data['post']['sections'][1]);
        $this->assertSame(150, substr_count(implode('', $data['post']['sections']), '<li>') - 5, '150 questions (+5 tips).');
        $this->assertSame('DECK-001', $data['decks'][0]['deck_code']);
        $this->assertSame('The PROVOKE NOW Editors', $data['post']['author']['name']);
        $this->assertSame(['BlogPosting', 'BreadcrumbList', 'FAQPage'], array_column($data['schema_json_ld']['@graph'], '@type'));
        $this->assertStringContainsString('/blog/'.BlogSeeder::PILLAR_SLUG, $this->get('/sitemap.xml')->getContent());
    }

    public function test_drafts_and_scheduled_posts_stay_private(): void
    {
        Post::create(['title' => 'Draft', 'slug' => 'draft', 'content' => 'x', 'is_published' => false]);
        Post::create(['title' => 'Later', 'slug' => 'later', 'content' => 'x', 'is_published' => true, 'published_at' => now()->addDay()]);

        $this->getJson('/api/v1/blog/posts/draft')->assertNotFound();
        $this->getJson('/api/v1/blog/posts/later')->assertNotFound();
        $this->getJson('/api/v1/blog/posts')->assertJsonPath('data.pagination.total', 0);
    }

    public function test_markdown_is_sanitised_and_titles_keep_the_brand_last(): void
    {
        $article = app(ArticleRenderer::class)->render("Intro <script>alert(1)</script>\n\n## Same\n\ntext\n\n## Same\n\n[x](javascript:alert(1))");
        $html = implode('', $article['sections']);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame(['same', 'same-2'], array_column($article['toc'], 'id'));

        $seo = app(SeoMetadataService::class);
        $title = $seo->branded('An extremely long primary keyword phrase that would never fit in a title tag');
        $this->assertLessThanOrEqual(60, mb_strlen($title));
        $this->assertStringEndsWith('| PROVOKE NOW', $title);
        $this->assertGreaterThanOrEqual(140, mb_strlen($seo->describe(str_repeat('word ', 20), ['Free discreet shipping in unbranded packaging.'])));
        $this->assertLessThanOrEqual(155, mb_strlen($seo->describe(str_repeat('word ', 25), ['Free discreet shipping in unbranded packaging.'])), 'Never pushed past 155.');
    }
}
