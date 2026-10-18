<?php

namespace Tests\Feature\Home;

use App\Enums\DeckSegment;
use App\Models\ClubSignup;
use App\Models\Deck;
use App\Models\HeroSlide;
use App\Models\InstagramPost;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\HeroSlideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_payload_returns_each_admin_managed_section(): void
    {
        $deck = Deck::factory()->create(['deck_code' => 'DECK-007', 'title' => 'Velvet Rope']);
        $inactiveDeck = Deck::factory()->inactive()->create(['deck_code' => 'DECK-008']);
        $box = Product::factory()->podDeck($deck)->create(['name' => 'Box', 'slug' => 'box', 'is_boxed_game' => true, 'is_featured' => true]);
        Product::factory()->accessory()->create(['name' => 'Corset', 'is_new_arrival' => true]);
        Product::factory()->accessory()->inactive()->create(['is_featured' => true]);

        HeroSlide::create(['title' => 'Deck slide', 'image' => 'https://cdn.provokenow.com/h1.jpg', 'target_type' => 'deck', 'deck_id' => $deck->id, 'sort_order' => 2]);
        HeroSlide::create(['title' => 'Product slide', 'image' => 'https://cdn.provokenow.com/h2.jpg', 'target_type' => 'product', 'product_id' => $box->id, 'cta_label' => 'Get the box', 'sort_order' => 1]);
        HeroSlide::create(['title' => 'Custom', 'image' => 'https://cdn.provokenow.com/h3.jpg', 'target_type' => 'custom', 'custom_url' => '/store?category=heels', 'sort_order' => 3]);
        HeroSlide::create(['title' => 'Dead link', 'image' => 'https://cdn.provokenow.com/h4.jpg', 'target_type' => 'deck', 'deck_id' => $inactiveDeck->id]);
        HeroSlide::create(['title' => 'Off', 'image' => 'https://cdn.provokenow.com/h5.jpg', 'target_type' => 'custom', 'custom_url' => '/', 'is_active' => false]);
        HeroSlide::create(['title' => 'Expired', 'image' => 'https://cdn.provokenow.com/h6.jpg', 'target_type' => 'custom', 'custom_url' => '/', 'ends_at' => now()->subDay()]);
        HeroSlide::create(['title' => 'Scheduled', 'image' => 'https://cdn.provokenow.com/h7.jpg', 'target_type' => 'custom', 'custom_url' => '/', 'starts_at' => now()->addDay()]);

        foreach (range(1, 4) as $i) {
            InstagramPost::create(['post_url' => "https://www.instagram.com/p/POST{$i}/", 'image' => "https://cdn.provokenow.com/ig{$i}.jpg", 'sort_order' => $i]);
        }
        SiteSetting::put('home_video', ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'headline' => 'After dark']);
        SiteSetting::put('instagram', ['handle' => 'provokenow', 'profile_url' => 'https://www.instagram.com/provokenow/']);

        $data = $this->getJson('/api/v1/home')->assertOk()->json('data');

        $this->assertSame(['Product slide', 'Deck slide', 'Custom'], array_column($data['hero_slides'], 'title'));
        $this->assertSame(['/store/box', '/decks/velvet-rope', '/store?category=heels'], array_column($data['hero_slides'], 'href'));
        $this->assertSame(['Get the box', 'Explore Deck', 'Discover'], array_column($data['hero_slides'], 'cta_label'));

        $this->assertSame(['Box'], array_column($data['popular_products'], 'name'));
        $this->assertSame(['Box'], array_column($data['boxed_games'], 'name'));
        $this->assertSame(['Corset'], array_column($data['new_arrivals'], 'name'));
        $this->assertSame(['DECK-007'], array_column($data['showcase_decks'], 'deck_code'));

        $this->assertSame('dQw4w9WgXcQ', $data['video']['youtube_id']);
        $this->assertSame(100, $data['video']['opacity'], 'Full opacity unless the admin lowers it.');
        $this->assertSame([0, 0], [$data['video']['start'], $data['video']['end_trim']]);
        $this->assertSame('After dark', $data['video']['headline']);
        $this->assertTrue($data['club']['enabled'], 'Provoke Club shows by default.');

        $this->assertCount(InstagramPost::HOMEPAGE_LIMIT, $data['instagram']['posts']);
        $this->assertSame('https://www.instagram.com/p/POST1/', $data['instagram']['posts'][0]['url']);
        $this->assertSame('provokenow', $data['instagram']['handle']);
    }

    public function test_draw_a_card_pool_is_the_picked_preview_cards_of_light_and_medium_decks(): void
    {
        $light = Deck::factory()->segment(DeckSegment::LightIgnition, 1)->withCards(10)->create();
        $medium = Deck::factory()->segment(DeckSegment::MediumSpice, 3)->withCards(10)->create();
        $unpicked = Deck::factory()->segment(DeckSegment::LightIgnition, 1)->withCards(10)->create();
        $taboo = Deck::factory()->segment(DeckSegment::Taboo, 5)->withCards(10)->create();
        $light->cards()->whereIn('card_number', [4, 8])->update(['is_preview' => true]);
        $medium->cards()->where('card_number', 2)->update(['is_preview' => true]);
        $taboo->cards()->where('card_number', 5)->update(['is_preview' => true]);

        $pool = collect($this->getJson('/api/v1/home')->assertOk()->json('data.showcase_cards'));

        $this->assertCount(3, $pool, 'Only picked preview cards; the deck without picks is never dealt.');
        $this->assertEqualsCanonicalizing([$light->id, $medium->id], $pool->pluck('deck.id')->unique()->values()->all());
        $this->assertNotContains($unpicked->id, $pool->pluck('deck.id'));
        $this->assertEqualsCanonicalizing([2, 4, 8], $pool->pluck('card.card_number')->all());
        $this->assertSame(10, $pool->first()['total_cards']);
    }

    public function test_slides_without_an_image_are_served_and_carry_their_video(): void
    {
        HeroSlide::create(['title' => 'Backdrop', 'target_type' => 'custom', 'custom_url' => '/decks', 'sort_order' => 1]);
        HeroSlide::create(['title' => 'Film', 'image' => 'https://cdn.provokenow.com/poster.jpg', 'video_url' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'video_start' => 3, 'video_end_trim' => 4, 'target_type' => 'custom', 'custom_url' => '/store', 'sort_order' => 2]);

        $slides = $this->getJson('/api/v1/home')->assertOk()->json('data.hero_slides');

        $this->assertSame(['Backdrop', 'Film'], array_column($slides, 'title'));
        $this->assertNull($slides[0]['image']);
        $this->assertNull($slides[0]['youtube_id']);
        $this->assertSame('dQw4w9WgXcQ', $slides[1]['youtube_id']);
        $this->assertSame([3, 4], [$slides[1]['video_start'], $slides[1]['video_end_trim']]);
        $this->assertSame([0, 0], [$slides[0]['video_start'], $slides[0]['video_end_trim']]);
        $this->assertSame('https://cdn.provokenow.com/poster.jpg', $slides[1]['image_mobile']);
    }

    public function test_hero_slide_seeder_fills_an_empty_slider_once(): void
    {
        $this->seed(HeroSlideSeeder::class);
        $this->seed(HeroSlideSeeder::class);

        $slides = $this->getJson('/api/v1/home')->json('data.hero_slides');
        $this->assertSame(array_column(HeroSlideSeeder::SLIDES, 1), array_column($slides, 'title'));
        $this->assertSame('/store/printed-game-boxes', $slides[3]['href']);
        $this->assertSame('Order Boxed Games', $slides[3]['cta_label']);

        // An admin who deleted slides doesn't get them back on a re-run.
        HeroSlide::query()->where('sort_order', '>', 1)->delete();
        $this->seed(HeroSlideSeeder::class);
        $this->assertSame(1, HeroSlide::count());
    }

    public function test_empty_site_returns_empty_sections_not_errors(): void
    {
        $data = $this->getJson('/api/v1/home')->assertOk()->json('data');

        foreach (['hero_slides', 'popular_products', 'showcase_decks', 'boxed_games', 'new_arrivals'] as $section) {
            $this->assertSame([], $data[$section], $section);
        }
        $this->assertNull($data['video']['youtube_id']);
        $this->assertSame([], $data['instagram']['posts']);
    }

    public function test_public_config_carries_footer_socials_and_drops_unsafe_links(): void
    {
        SiteSetting::put('socials', ['instagram' => 'https://www.instagram.com/provokenow/', 'tiktok' => 'javascript:alert(1)', 'x' => '']);
        SiteSetting::put('brand_story', 'Conversation, by design.');

        $this->getJson('/api/v1/config/public')
            ->assertJsonPath('data.socials', ['instagram' => 'https://www.instagram.com/provokenow/'])
            ->assertJsonPath('data.brand_story', 'Conversation, by design.');
    }

    public function test_provoke_club_signup_is_idempotent_and_requires_consent(): void
    {
        $this->postJson('/api/v1/club/subscribe', ['email' => 'ava@example.com'])->assertUnprocessable()->assertJsonValidationErrors('consent');

        $this->postJson('/api/v1/club/subscribe', ['email' => 'Ava@Example.com ', 'consent' => true])->assertCreated()->assertJsonPath('success', true);
        // Same answer for an existing address: the endpoint never reveals who is on the list.
        $this->postJson('/api/v1/club/subscribe', ['email' => 'ava@example.com', 'consent' => true])->assertCreated();

        $this->assertSame(1, ClubSignup::count());
        $this->assertSame('ava@example.com', ClubSignup::first()->email);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/club/subscribe', ['email' => 'member@example.com', 'consent' => true])->assertCreated();
        $this->assertSame($user->id, ClubSignup::firstWhere('email', 'member@example.com')->user_id);
    }
}
