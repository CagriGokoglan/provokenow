<?php

namespace Tests\Feature\Catalog;

use App\Enums\DeckSegment;
use App\Enums\EntitlementStatus;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeckAccessTest extends TestCase
{
    use RefreshDatabase;

    private Deck $deck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deck = Deck::factory()->withCards(50)->create(['deck_code' => 'DECK-001']);
    }

    public function test_guests_and_unverified_accounts_see_no_card_content(): void
    {
        $response = $this->getJson('/api/v1/decks/DECK-001')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.lock_reason', 'verification_required')
            ->assertJsonPath('data.total_cards', 50)
            ->assertJsonCount(0, 'data.cards');
        $this->assertStringNotContainsString('"Card 1"', $response->getContent(), 'Not even in FAQs or structured data.');

        Sanctum::actingAs(User::factory()->unverified()->create());
        $this->getJson('/api/v1/decks/DECK-001')->assertJsonPath('data.lock_reason', 'verification_required')->assertJsonCount(0, 'data.cards');
    }

    public function test_verified_member_sees_the_two_preview_cards_only(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/decks/DECK-001')
            ->assertJsonPath('data.lock_reason', 'not_entitled')
            ->assertJsonCount(2, 'data.cards')
            ->assertJsonPath('data.cards.0.card_number', 1)
            ->assertJsonPath('data.cards.1.card_number', 2);
        $this->assertStringNotContainsString('"Card 3"', $response->getContent());
    }

    public function test_advanced_and_taboo_decks_need_passion_mode(): void
    {
        $taboo = Deck::factory()->segment(DeckSegment::Taboo, 5)->withCards(3)->create(['deck_code' => 'DECK-090']);

        $this->getJson('/api/v1/decks/DECK-090')->assertForbidden()->assertJsonPath('errors.reason.0', 'adult_locked');
        Sanctum::actingAs($member = User::factory()->create());
        $this->withToken('member-token'); // signed-in requests skip the public response cache
        $this->getJson('/api/v1/decks/DECK-090')->assertForbidden()->assertJsonPath('errors.reason.0', 'adult_locked');
        $this->assertNotContains('DECK-090', collect($this->getJson('/api/v1/decks')->json('data.items'))->pluck('deck_code'));

        // Switching Passion mode on (verified accounts only) opens the tier.
        $this->patchJson('/api/v1/user/profile', ['is_adult_unlocked' => true])->assertOk()->assertJsonPath('data.user.is_adult_unlocked', true);
        $this->getJson('/api/v1/decks/DECK-090')->assertOk()->assertJsonCount(2, 'data.cards');
        $this->assertContains('DECK-090', collect($this->getJson('/api/v1/decks')->json('data.items'))->pluck('deck_code'));
        $this->assertNotNull($member->fresh()->adult_unlocked_at);

        $this->patchJson('/api/v1/user/profile', ['is_adult_unlocked' => false])->assertJsonPath('data.user.is_adult_unlocked', false);
        $this->getJson('/api/v1/decks/DECK-090')->assertForbidden();

        // Owners keep a deck they bought, whatever the switch.
        $member->entitlements()->create(['deck_id' => $taboo->id, 'granted_at' => now()]);
        $this->getJson('/api/v1/decks/DECK-090')->assertOk()->assertJsonCount(3, 'data.cards');

        // Unverified accounts cannot switch it on.
        Sanctum::actingAs(User::factory()->unverified()->create());
        $this->patchJson('/api/v1/user/profile', ['is_adult_unlocked' => true])->assertUnprocessable()->assertJsonValidationErrors('is_adult_unlocked');
    }

    public function test_deck_page_content_is_written_per_deck(): void
    {
        $this->deck->update(['whats_inside' => '50 cards and a ribbon.', 'how_to_play' => "- Draw\n- Read"]);

        $this->getJson('/api/v1/decks/DECK-001')
            ->assertJsonPath('data.deck.page_content.whats_inside', '50 cards and a ribbon.')
            ->assertJsonPath('data.deck.page_content.how_to_play', "- Draw\n- Read")
            ->assertJsonPath('data.deck.page_content.who_its_for', null);

        $this->getJson('/api/v1/decks')->assertJsonMissingPath('data.items.0.page_content');
    }

    public function test_deck_urls_come_from_the_title_and_old_addresses_keep_resolving(): void
    {
        $deck = Deck::factory()->create(['deck_code' => 'DECK-300', 'title' => 'Velvet Hours']);
        $this->assertSame('velvet-hours', $deck->slug);
        $this->assertSame('velvet-hours-2', Deck::factory()->create(['title' => 'Velvet Hours'])->slug, 'Unique.');
        $this->assertSame('taboo-deck', Deck::factory()->create(['title' => 'Taboo'])->slug, 'Never a level page.');

        $deck->update(['slug' => 'velvet-hours-after-dark']);
        $this->assertSame(['velvet-hours'], $deck->fresh()->previous_slugs);

        foreach (['velvet-hours-after-dark', 'velvet-hours', 'deck-300', 'DECK-300'] as $address) {
            $this->getJson("/api/v1/decks/{$address}")->assertOk()->assertJsonPath('data.deck.slug', 'velvet-hours-after-dark');
        }
        $this->getJson('/api/v1/decks/no-such-deck')->assertNotFound();
    }

    public function test_authenticated_user_without_entitlement_sees_preview_only(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/decks/deck-001')
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonCount(2, 'data.cards');
    }

    public function test_entitled_user_sees_all_50_cards(): void
    {
        $user = User::factory()->create();
        $user->entitlements()->create(['deck_id' => $this->deck->id, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/decks/DECK-001')
            ->assertOk()
            ->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.lock_reason', null)
            ->assertJsonCount(50, 'data.cards')
            ->assertJsonPath('data.cards.49.card_number', 50);
    }

    public function test_revoked_or_expired_entitlements_do_not_unlock(): void
    {
        $user = User::factory()->create();
        $user->entitlements()->create(['deck_id' => $this->deck->id, 'granted_at' => now(), 'status' => EntitlementStatus::Revoked]);
        $user->entitlements()->create(['deck_id' => $this->deck->id, 'granted_at' => now()->subYear(), 'expires_at' => now()->subDay()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/decks/DECK-001')->assertJsonPath('data.is_locked', true)->assertJsonCount(2, 'data.cards');
        $this->getJson('/api/v1/user/entitlements')->assertOk()->assertJsonCount(0, 'data.entitlements');
    }

    public function test_index_lists_active_decks_without_card_payloads(): void
    {
        Deck::factory()->inactive()->create(['deck_code' => 'DECK-002']);
        Deck::factory()->segment(DeckSegment::Taboo, 4)->create(['deck_code' => 'DECK-003']);

        // Guests: Light / Medium only.
        $this->getJson('/api/v1/decks')->assertJsonPath('data.pagination.total', 1);
        Sanctum::actingAs(User::factory()->adult()->create());
        $this->withToken('member-token'); // signed-in requests skip the public response cache

        $response = $this->getJson('/api/v1/decks')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.deck_code', 'DECK-001')
            ->assertJsonPath('data.items.1.segment', 'Taboo')
            ->assertJsonPath('data.items.1.vault_protected', true)
            ->assertJsonStructure(['data' => ['items' => [['id', 'deck_code', 'title', 'segment', 'intensity_level', 'core_theme']]]]);

        $this->assertStringNotContainsString('content', $response->getContent());
        $this->getJson('/api/v1/decks?segment=Taboo')->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/decks/DECK-002')->assertNotFound()->assertJsonPath('success', false);
        $this->getJson('/api/v1/products/missing')->assertNotFound()->assertJsonPath('message', 'Resource not found.');
    }

    public function test_catalogue_has_no_deck_cap_and_pages_through_every_deck(): void
    {
        foreach (range(2, 150) as $n) {
            Deck::factory()->create(['deck_code' => sprintf('DECK-%03d', $n)]);
        }

        $first = $this->getJson('/api/v1/decks?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 150)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonCount(100, 'data.items');
        $second = $this->getJson('/api/v1/decks?per_page=100&page=2')->assertJsonCount(50, 'data.items');

        $codes = collect([...$first->json('data.items'), ...$second->json('data.items')])->pluck('deck_code');
        $this->assertSame(150, $codes->unique()->count());
        $this->assertContains('DECK-150', $codes);
    }

    public function test_vault_passcode_gates_protected_decks_until_verified(): void
    {
        $deck = Deck::factory()->segment(DeckSegment::Taboo, 4)->withCards(3)->create(['deck_code' => 'DECK-050']);
        $user = User::factory()->create(['password' => 'Account-Pass-1']);
        $user->profile()->create();
        $user->entitlements()->create(['deck_id' => $deck->id, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        // No passcode set: the vault is opt-in, so the entitled deck is open.
        $this->getJson('/api/v1/decks/DECK-050')->assertJsonPath('data.is_locked', false);

        $this->postJson('/api/v1/user/vault/passcode', ['passcode' => '2468', 'passcode_confirmation' => '2468', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/api/v1/user/vault/passcode', ['passcode' => '2468', 'passcode_confirmation' => '2468', 'current_password' => 'Account-Pass-1'])
            ->assertOk()->assertJsonPath('data.has_vault_passcode', true);
        $this->assertNotSame('2468', $user->profile()->first()->digital_vault_passcode_hash);

        $this->getJson('/api/v1/decks/DECK-050')
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.lock_reason', 'vault_locked')
            ->assertJsonCount(2, 'data.cards');
        // Non-protected decks are unaffected.
        $this->getJson('/api/v1/decks/DECK-001')->assertJsonPath('data.lock_reason', 'not_entitled');

        $this->postJson('/api/v1/user/vault/verify', ['passcode' => '0000'])
            ->assertUnprocessable()->assertJsonValidationErrors('passcode');
        $this->postJson('/api/v1/user/vault/verify', ['passcode' => '2468'])
            ->assertOk()->assertJsonPath('data.vault_unlocked', true);

        $this->getJson('/api/v1/decks/DECK-050')->assertJsonPath('data.is_locked', false)->assertJsonCount(3, 'data.cards');
    }

    public function test_vault_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/user/vault/verify', ['passcode' => '1234'])->assertUnauthorized();
        $this->getJson('/api/v1/user/entitlements')->assertUnauthorized();
    }
}
