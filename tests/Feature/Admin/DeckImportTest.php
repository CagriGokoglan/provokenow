<?php

namespace Tests\Feature\Admin;

use App\Enums\DeckSegment;
use App\Enums\UserRole;
use App\Filament\Resources\DeckResource;
use App\Filament\Resources\DeckResource\Pages\ListDecks;
use App\Models\Deck;
use App\Models\DeckCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeckImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin);
    }

    private function deckJson(array $overrides = []): string
    {
        return json_encode([
            'deck_code' => 'deck-007',
            'title' => 'Velvet Rope',
            'segment' => 'Medium Spice',
            'intensity_level' => 5,
            'core_theme_keywords' => ['play', 'trust', 'role reversal'],
            'fantasy_categories' => ['Roleplay', 'does-not-exist'],
            'whats_inside' => "Fifty cards.\n\nVelvet box.",
            'how_to_play' => "- Draw\n- Answer",
            'who_its_for' => 'Couples who like a little theatre.',
            'digital_price' => '$12.99',
            'digital_sku' => 'DIG-VR',
            'faqs' => [['question' => 'Q1?', 'answer' => 'A1.'], ['question' => 'Q2?', 'answer' => 'A2.']],
            'meta_title' => 'Velvet Rope: Roleplay Card Game | PROVOKE NOW',
            'meta_description' => 'A roleplay card game for couples.',
            ...$overrides,
        ]);
    }

    public function test_a_deck_is_imported_in_one_go_and_opens_its_edit_page(): void
    {
        DeckCategory::factory()->create(['name' => 'Roleplay', 'slug' => 'roleplay']);

        Livewire::test(ListDecks::class)
            ->callAction('importDeck', data: ['json' => $this->deckJson(), 'update_existing' => false])
            ->assertHasNoActionErrors()
            ->assertRedirect(DeckResource::getUrl('edit', ['record' => Deck::firstWhere('deck_code', 'DECK-007')]))
            ->assertNotified('DECK-007 — Velvet Rope imported');

        $deck = Deck::firstWhere('deck_code', 'DECK-007');
        $this->assertSame([DeckSegment::MediumSpice, 5, 'play, trust, role reversal', false], [$deck->segment, $deck->intensity_level, $deck->core_theme_keywords, $deck->is_active]);
        $this->assertSame(['roleplay'], $deck->categories()->pluck('slug')->all(), 'Unknown categories are skipped.');
        $this->assertSame("- Draw\n- Answer", $deck->how_to_play);
        $this->assertSame(2, count($deck->faqs));
        $this->assertSame([1299, 'DIG-VR', true], [$deck->digitalProduct->price_cents, $deck->digitalProduct->sku, $deck->is_digital_sale_active]);
        $this->assertSame('Velvet Rope: Roleplay Card Game | PROVOKE NOW', $deck->meta_title);
    }

    public function test_invalid_json_and_rule_breaks_are_reported_without_saving(): void
    {
        Livewire::test(ListDecks::class)->callAction('importDeck', data: ['json' => '{ not json']);
        Livewire::test(ListDecks::class)->callAction('importDeck', data: ['json' => $this->deckJson(['segment' => 'Spicy', 'intensity_level' => 6, 'meta_title' => str_repeat('x', 61)])])
            ->assertNotified('The JSON needs fixing');

        $this->assertSame(0, Deck::count());
    }

    public function test_an_existing_deck_is_only_overwritten_when_asked(): void
    {
        Deck::factory()->create(['deck_code' => 'DECK-007', 'title' => 'Old title', 'is_active' => true]);

        Livewire::test(ListDecks::class)->callAction('importDeck', data: ['json' => $this->deckJson(), 'update_existing' => false])
            ->assertNotified('The JSON needs fixing');
        $this->assertSame('Old title', Deck::first()->title);

        Livewire::test(ListDecks::class)->callAction('importDeck', data: ['json' => $this->deckJson(), 'update_existing' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(['Velvet Rope', true], [Deck::first()->title, Deck::first()->is_active], 'Updated in place; stays live.');
        $this->assertSame(1, Deck::count());
    }
}
