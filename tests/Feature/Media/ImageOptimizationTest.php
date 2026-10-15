<?php

namespace Tests\Feature\Media;

use App\Models\Deck;
use App\Models\HeroSlide;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function upload(string $path, int $width = 3000, int $height = 1500): string
    {
        Storage::disk('public')->put($path, UploadedFile::fake()->image(basename($path), $width, $height)->getContent());

        return $path;
    }

    public function test_uploaded_images_become_capped_webp_and_the_original_is_removed(): void
    {
        $desktop = $this->upload('home/hero/desktop.jpg');
        $mobile = $this->upload('home/hero/mobile.png', 1080, 1600);

        $slide = HeroSlide::create(['title' => 'Film', 'image' => $desktop, 'image_mobile' => $mobile, 'target_type' => 'custom', 'custom_url' => '/decks']);
        $slide->refresh();

        $this->assertSame('home/hero/desktop.o.webp', $slide->image);
        $this->assertSame('home/hero/mobile.o.webp', $slide->image_mobile);
        Storage::disk('public')->assertMissing([$desktop, $mobile]);

        [$width, $height, $type] = getimagesizefromstring(Storage::disk('public')->get($slide->image));
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame([2400, 1200], [$width, $height], 'Scaled down to the 2400 px cap, aspect kept.');
    }

    public function test_product_galleries_and_share_images_use_their_own_profiles(): void
    {
        $product = Product::factory()->accessory()->create([
            'metadata' => ['images' => [$this->upload('products/a.jpg', 1600, 2000), $this->upload('products/b.png', 800, 800)]],
            'og_image' => $this->upload('seo/products/share.png', 2400, 1260),
        ]);
        $product->refresh();

        $this->assertSame(['products/a.o.webp', 'products/b.o.webp'], $product->metadata['images']);
        $this->assertSame('seo/products/share-og.jpg', $product->og_image);
        [$width, , $type] = getimagesizefromstring(Storage::disk('public')->get($product->og_image));
        $this->assertSame([1200, IMAGETYPE_JPEG], [$width, $type]);
    }

    public function test_vector_art_and_remote_urls_are_left_alone(): void
    {
        Storage::disk('public')->put('decks/artwork/back.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $deck = Deck::factory()->create(['card_back_bg_url' => 'decks/artwork/back.svg', 'og_image' => 'https://cdn.provokenow.com/og.jpg']);

        $this->assertSame(['decks/artwork/back.svg', 'https://cdn.provokenow.com/og.jpg'], [$deck->fresh()->card_back_bg_url, $deck->fresh()->og_image]);
    }

    public function test_saving_a_stale_path_from_an_open_admin_form_keeps_the_converted_image(): void
    {
        $path = $this->upload('home/hero/stale.jpg', 1200, 600);
        $slide = HeroSlide::create(['title' => 'Film', 'image' => $path, 'target_type' => 'custom', 'custom_url' => '/']);
        $this->assertSame('home/hero/stale.o.webp', $slide->fresh()->image);

        // The edit form still holds the original path and is saved again.
        $slide->update(['image' => $path, 'title' => 'Film, retitled']);

        $this->assertSame('home/hero/stale.o.webp', $slide->fresh()->image);
        Storage::disk('public')->assertExists('home/hero/stale.o.webp');
    }

    public function test_command_converts_existing_images_once(): void
    {
        $path = $this->upload('home/hero/legacy.jpg', 1200, 600);
        HeroSlide::withoutEvents(fn () => HeroSlide::create(['title' => 'Old', 'image' => $path, 'target_type' => 'custom', 'custom_url' => '/']));

        $this->artisan('provoke:optimize-images', ['--dry-run' => true])->expectsOutputToContain('1 images would be converted')->assertSuccessful();
        Storage::disk('public')->assertExists($path);

        $this->artisan('provoke:optimize-images')->expectsOutputToContain('1 images converted')->assertSuccessful();
        $this->assertSame('home/hero/legacy.o.webp', HeroSlide::first()->image);

        $this->artisan('provoke:optimize-images')->expectsOutputToContain('0 images converted')->assertSuccessful();
    }
}
