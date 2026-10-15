<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\HeroSlideResource\Pages\CreateHeroSlide;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Deck;
use App\Models\HeroSlide;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class HomepageAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin);
    }

    public function test_site_settings_are_saved_and_served_to_the_homepage(): void
    {
        Livewire::test(SiteSettings::class)
            ->fillForm([
                'home_video' => ['url' => 'https://cdn.provokenow.com/film.mp4', 'headline' => 'After dark'],
                'club' => ['enabled' => false, 'headline' => 'Provoke Club'],
                'instagram' => ['handle' => 'provokenow'],
                'socials' => ['instagram' => 'https://www.instagram.com/provokenow/'],
                'brand_story' => 'Conversation, by design.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Site settings saved');

        $this->assertSame('After dark', SiteSetting::value('home_video')['headline']);
        $this->getJson('/api/v1/home')
            ->assertJsonPath('data.video.url', 'https://cdn.provokenow.com/film.mp4')
            ->assertJsonPath('data.club.enabled', false);

        Livewire::test(SiteSettings::class)
            ->fillForm(['socials' => ['tiktok' => 'http://insecure.example']])
            ->call('save')
            ->assertHasFormErrors(['socials.tiktok']);
    }

    public function test_hero_slide_links_to_a_deck(): void
    {
        Storage::fake('public');
        $deck = Deck::factory()->create(['deck_code' => 'DECK-011']);

        Livewire::test(CreateHeroSlide::class)
            ->fillForm(['title' => 'Slow Burn', 'image' => UploadedFile::fake()->image('hero.jpg', 2400, 1200), 'target_type' => 'deck', 'deck_id' => $deck->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $slide = HeroSlide::firstOrFail();
        $this->assertSame('/decks/deck-011', $slide->href());
        Storage::disk('public')->assertExists($slide->image);
        $this->assertSame('Explore Deck', $slide->ctaLabel());

        Livewire::test(CreateHeroSlide::class)
            ->fillForm(['title' => 'Bad link', 'image' => UploadedFile::fake()->image('hero.jpg'), 'target_type' => 'custom', 'custom_url' => 'javascript:alert(1)'])
            ->call('create')
            ->assertHasFormErrors(['custom_url']);
    }

    public function test_product_category_and_showcase_flags_are_saved(): void
    {
        $dice = ProductCategory::factory()->create(['name' => 'Dice', 'slug' => 'dice']);
        $product = Product::factory()->accessory()->create();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['product_category_id' => $dice->id, 'is_featured' => true, 'is_boxed_game' => true, 'sort_order' => 3])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame($dice->id, $product->product_category_id);
        $this->assertTrue($product->is_featured);
        $this->assertTrue($product->is_boxed_game);
        $this->assertFalse($product->is_new_arrival);
        $this->assertSame(3, $product->sort_order);
    }
}
