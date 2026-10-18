<?php

namespace Tests\Feature\Legal;

use App\Enums\UserRole;
use App\Filament\Resources\LegalPageResource\Pages\EditLegalPage;
use App\Filament\Resources\LegalPageResource\Pages\ListLegalPages;
use App\Models\LegalPage;
use App\Models\User;
use Database\Seeders\LegalPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_six_pages_with_company_details_and_never_overwrites_edits(): void
    {
        $this->seed(LegalPageSeeder::class);

        $this->assertSame(
            ['terms-of-service', 'privacy-policy', 'refund-policy', 'shipping-policy', 'contact', 'age-disclaimer', 'about-us', 'brand-standard'],
            LegalPage::orderBy('sort_order')->pluck('slug')->all(),
        );
        $this->assertStringContainsString('GKLN ENTERPRISES LLC', LegalPage::firstWhere('slug', 'terms-of-service')->content);
        $this->assertStringContainsString('7 days', LegalPage::firstWhere('slug', 'refund-policy')->content);
        $this->assertStringContainsString('non-refundable', LegalPage::firstWhere('slug', 'refund-policy')->content);
        $this->assertStringContainsString('3–5 business days', LegalPage::firstWhere('slug', 'shipping-policy')->content);
        $this->assertStringContainsString('support@provokenow.com', LegalPage::firstWhere('slug', 'contact')->content);
        $this->assertStringContainsString('GKLN ENTERPRISES LLC', LegalPage::firstWhere('slug', 'about-us')->content);
        $this->assertStringContainsString('Charges appear on your statement as **GKLN ENTERPRISES LLC**', LegalPage::firstWhere('slug', 'about-us')->content);
        $this->assertStringContainsString('PROVOKE NOW™', LegalPage::firstWhere('slug', 'brand-standard')->content);

        LegalPage::firstWhere('slug', 'privacy-policy')->update(['content' => 'Edited by the admin.']);
        $this->seed(LegalPageSeeder::class);
        $this->assertSame('Edited by the admin.', LegalPage::firstWhere('slug', 'privacy-policy')->content);
        $this->assertSame(8, LegalPage::count());
    }

    public function test_api_serves_published_pages_as_safe_html(): void
    {
        $this->seed(LegalPageSeeder::class);
        LegalPage::firstWhere('slug', 'age-disclaimer')->update(['is_published' => false]);
        LegalPage::firstWhere('slug', 'terms-of-service')->update(['content' => "## Rules\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1)) **bold**"]);

        $this->getJson('/api/v1/legal-pages')->assertOk()->assertJsonCount(7, 'data.pages')->assertJsonPath('data.pages.0.slug', 'terms-of-service');

        $html = $this->getJson('/api/v1/legal-pages/terms-of-service')
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Terms of Service | PROVOKE NOW')
            ->assertJsonPath('data.company.name', 'GKLN ENTERPRISES LLC')
            ->assertJsonPath('data.contact_form', false)
            ->json('data.page.content_html');
        $this->assertStringContainsString('<h2>Rules</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        $this->getJson('/api/v1/legal-pages/contact')->assertJsonPath('data.contact_form', true);
        $this->getJson('/api/v1/legal-pages/age-disclaimer')->assertNotFound();
        $this->getJson('/api/v1/legal-pages/nope')->assertNotFound();
    }

    public function test_admin_can_list_and_edit_legal_pages(): void
    {
        $this->seed(LegalPageSeeder::class);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::SuperAdmin])->save();
        $this->actingAs($admin);
        $page = LegalPage::firstWhere('slug', 'shipping-policy');

        Livewire::test(ListLegalPages::class)->assertCanSeeTableRecords([$page]);
        Livewire::test(EditLegalPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['content' => 'Ships in 2 days.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Ships in 2 days.', $page->fresh()->content);
        $this->getJson('/api/v1/legal-pages/shipping-policy')->assertJsonPath('data.page.content_html', "<p>Ships in 2 days.</p>\n");
    }
}
