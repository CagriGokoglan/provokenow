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

    public function test_guest_cannot_see_full_deck_only_preview_card(): void
    {
        $response = $this->getJson('/api/v1/decks/DECK-001')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.lock_reason', 'not_entitled')
            ->assertJsonPath('data.total_cards', 50)
            ->assertJsonCount(1, 'data.cards')
            ->assertJsonPath('data.cards.0.card_number', 1);

        $this->assertStringNotContainsString('"Card 2"', $response->getContent());
    }

    public function test_authenticated_user_without_entitlement_sees_preview_only(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/decks/deck-001')
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonCount(1, 'data.cards');
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

        $this->getJson('/api/v1/decks/DECK-001')->assertJsonPath('data.is_locked', true)->assertJsonCount(1, 'data.cards');
        $this->getJson('/api/v1/user/entitlements')->assertOk()->assertJsonCount(0, 'data.entitlements');
    }

    public function test_index_lists_active_decks_without_card_payloads(): void
    {
        Deck::factory()->inactive()->create(['deck_code' => 'DECK-002']);
        Deck::factory()->segment(DeckSegment::Taboo, 4)->create(['deck_code' => 'DECK-003']);

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
            ->assertJsonCount(1, 'data.cards');
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
