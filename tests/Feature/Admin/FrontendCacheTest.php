<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\ProductCategoryResource\Pages\ManageProductCategories;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FrontendCacheTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://127.0.0.1:3000/revalidate';

    protected function setUp(): void
    {
        parent::setUp();
        config(['provoke.frontend.revalidate_url' => self::URL, 'provoke.frontend.revalidate_secret' => 'test-secret']);
        Http::fake([self::URL => Http::response(['revalidated' => true])]);
    }

    public function test_content_changes_refresh_the_web_app_once_per_request(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Heels']);
        $category->update(['name' => 'Stilettos']);
        $category->delete();

        Http::assertNothingSent();
        $this->runDeferred();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->hasHeader('X-Revalidate-Secret', 'test-secret'));
    }

    public function test_drag_and_drop_reordering_in_the_admin_refreshes_the_web_app(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $a = ProductCategory::factory()->create(['sort_order' => 1]);
        $b = ProductCategory::factory()->create(['sort_order' => 2]);
        app(DeferredCallbackCollection::class)->forget('frontend-cache');

        $this->actingAs($admin);
        Livewire::test(ManageProductCategories::class)->call('reorderTable', [$b->id, $a->id]);
        $this->runDeferred();

        $this->assertSame(1, $b->fresh()->sort_order);
        Http::assertSentCount(1);
    }

    public function test_nothing_is_sent_without_a_shared_secret(): void
    {
        config(['provoke.frontend.revalidate_secret' => null]);

        ProductCategory::factory()->create();
        $this->runDeferred();

        Http::assertNothingSent();
    }

    private function runDeferred(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }
}
