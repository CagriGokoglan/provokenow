<?php

namespace Tests\Feature\Catalog;

use App\Enums\UserRole;
use App\Filament\Resources\DeckResource\Pages\EditDeck;
use App\Filament\Resources\DeckResource\RelationManagers\CardsRelationManager;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PreviewCardsTest extends TestCase
{
    use RefreshDatabase;

    private function numbers(array $cards): array
    {
        return array_column($cards, 'card_number');
    }

    public function test_without_picks_the_first_two_cards_are_the_preview_and_the_deck_is_not_homepage_eligible(): void
    {
        Deck::factory()->withCards(10)->create(['deck_code' => 'DECK-001']);

        $data = $this->getJson('/api/v1/decks/DECK-001')->assertOk()->json('data');
        $this->assertSame([1, 2], $this->numbers($data['cards']));
        $this->assertSame([1, 2], $this->numbers($data['preview_cards']));
        $this->assertFalse($data['deck']['is_homepage_eligible']);

        $item = $this->getJson('/api/v1/decks')->json('data.items.0');
        $this->assertSame([1, 2], $this->numbers($item['preview_cards']));
        $this->assertFalse($item['is_homepage_eligible']);
    }

    public function test_picked_cards_are_the_preview_everywhere_and_never_unlock_the_rest(): void
    {
        $deck = Deck::factory()->withCards(10)->create(['deck_code' => 'DECK-001']);
        $deck->cards()->whereIn('card_number', [7, 3])->update(['is_preview' => true]);

        $response = $this->getJson('/api/v1/decks/DECK-001')->assertOk()->assertJsonPath('data.is_locked', true);
        $this->assertSame([3, 7], $this->numbers($response->json('data.cards')), 'Guests play exactly the picked cards.');
        $this->assertSame([3, 7], $this->numbers($response->json('data.preview_cards')));
        $this->assertTrue($response->json('data.deck.is_homepage_eligible'));
        $this->assertStringNotContainsString('"Card 1"', $response->getContent(), 'Card 1 is no longer free.');

        $this->assertSame([3, 7], $this->numbers($this->getJson('/api/v1/decks')->json('data.items.0.preview_cards')));

        // Owners get every card; the sample questions stay the picked ones.
        $owner = User::factory()->create();
        $owner->entitlements()->create(['deck_id' => $deck->id, 'granted_at' => now()]);
        Sanctum::actingAs($owner);
        $data = $this->getJson('/api/v1/decks/DECK-001')->json('data');
        $this->assertCount(10, $data['cards']);
        $this->assertSame([3, 7], $this->numbers($data['preview_cards']));
    }

    public function test_a_single_pick_is_completed_with_the_lowest_numbered_card(): void
    {
        $deck = Deck::factory()->withCards(10)->create(['deck_code' => 'DECK-001']);
        $deck->cards()->where('card_number', 9)->update(['is_preview' => true]);

        $this->assertSame([1, 9], $this->numbers($this->getJson('/api/v1/decks/DECK-001')->json('data.preview_cards')));
        $this->assertTrue($deck->fresh()->hasPickedPreview());
    }

    public function test_no_more_than_two_preview_cards_per_deck(): void
    {
        $deck = Deck::factory()->withCards(5)->create();
        $other = Deck::factory()->withCards(5)->create();
        $deck->cards()->where('card_number', 1)->first()->update(['is_preview' => true]);
        $deck->cards()->where('card_number', 2)->first()->update(['is_preview' => true]);
        $other->cards()->where('card_number', 1)->first()->update(['is_preview' => true]);

        try {
            $deck->cards()->where('card_number', 3)->first()->update(['is_preview' => true]);
            $this->fail('A third preview card was saved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_preview', $e->errors());
        }
        $this->assertSame(2, $deck->cards()->where('is_preview', true)->count());

        // Re-saving an existing preview card is fine.
        $deck->cards()->where('card_number', 1)->first()->update(['title' => 'Renamed']);
        $this->assertSame('Renamed', $deck->cards()->where('card_number', 1)->value('title'));
    }

    public function test_admin_picks_preview_cards_in_the_cards_table_and_form_with_the_limit_enforced(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin);

        $deck = Deck::factory()->withCards(5)->create();
        $cards = $deck->cards()->orderBy('card_number')->get();
        $manager = ['ownerRecord' => $deck, 'pageClass' => EditDeck::class];

        Livewire::test(CardsRelationManager::class, $manager)
            ->assertSee('NOT shown on the homepage')
            ->callTableAction('togglePreview', $cards[3])
            ->callTableAction('togglePreview', $cards[1])
            ->callTableAction('togglePreview', $cards[4])
            ->assertNotified('Only 2 preview cards per deck');
        $this->assertSame([2, 4], $deck->cards()->where('is_preview', true)->orderBy('card_number')->pluck('card_number')->all());

        Livewire::test(CardsRelationManager::class, $manager)
            ->assertSee('Preview cards: #2, #4 (2 / 2)')
            ->callTableAction('edit', $cards[0], data: ['is_preview' => true])
            ->assertHasTableActionErrors(['is_preview']);
        $this->assertFalse($cards[0]->fresh()->is_preview);

        // Untick one, then the form accepts a new pick.
        Livewire::test(CardsRelationManager::class, $manager)
            ->callTableAction('togglePreview', $cards[3])
            ->callTableAction('edit', $cards[0], data: ['is_preview' => true])
            ->assertHasNoTableActionErrors();
        $this->assertSame([1, 2], $deck->cards()->where('is_preview', true)->orderBy('card_number')->pluck('card_number')->all());
    }
}
