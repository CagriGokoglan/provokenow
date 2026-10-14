<?php

namespace Tests\Feature\Catalog;

use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CardArtworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_artwork_urls_are_exposed_and_uploaded_svgs_are_sanitised(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('decks/artwork/front.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="5" height="7" fill="#1F121C"/></svg>');

        Deck::factory()->create([
            'deck_code' => 'DECK-001',
            'card_front_bg_url' => 'decks/artwork/front.svg',
            'card_back_bg_url' => 'https://cdn.provokenow.com/backs/noir.svg',
        ]);

        $svg = Storage::disk('public')->get('decks/artwork/front.svg');
        $this->assertStringNotContainsString('script', $svg);
        $this->assertStringNotContainsString('onload', $svg);
        $this->assertStringContainsString('<rect', $svg);

        $this->getJson('/api/v1/decks/DECK-001')
            ->assertJsonPath('data.deck.card_front_bg_url', Storage::disk('public')->url('decks/artwork/front.svg'))
            ->assertJsonPath('data.deck.card_back_bg_url', 'https://cdn.provokenow.com/backs/noir.svg');
        $this->getJson('/api/v1/decks')->assertJsonPath('data.items.0.card_back_bg_url', 'https://cdn.provokenow.com/backs/noir.svg');
    }
}
