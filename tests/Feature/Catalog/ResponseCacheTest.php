<?php

namespace Tests\Feature\Catalog;

use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalogue_reads_are_cached_until_content_changes(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Heels']);

        $this->getJson('/api/v1/taxonomies')->assertHeader('X-Response-Cache', 'MISS');
        $this->getJson('/api/v1/taxonomies')
            ->assertHeader('X-Response-Cache', 'HIT')
            ->assertJsonPath('data.product_categories.0.name', 'Heels');
        $this->assertStringContainsString('public', $this->getJson('/api/v1/taxonomies')->headers->get('Cache-Control'));

        $category->update(['name' => 'Stilettos']);

        $this->getJson('/api/v1/taxonomies')
            ->assertHeader('X-Response-Cache', 'MISS')
            ->assertJsonPath('data.product_categories.0.name', 'Stilettos');
    }

    public function test_requests_with_a_token_are_never_cached(): void
    {
        $token = User::factory()->create()->createToken('web')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/home')->assertOk()->assertHeaderMissing('X-Response-Cache');
        $this->flushHeaders()->getJson('/api/v1/home')->assertHeader('X-Response-Cache', 'MISS');
    }
}
