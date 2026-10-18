<?php

namespace Tests\Feature\Catalog;

use App\Models\Card;
use App\Models\Deck;
use App\Models\LegalPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LineBreaksTest extends TestCase
{
    use RefreshDatabase;

    public function test_literal_backslash_n_is_saved_as_a_real_line_break(): void
    {
        $deck = Deck::factory()->create([
            'how_to_play' => 'Pick a cozy spot.\n- Shuffle the deck.\r\n- Take turns.',
            'faqs' => [['question' => 'Q?', 'answer' => 'Line one.\nLine two.']],
        ]);
        $card = Card::factory()->for($deck)->create(['content_payload' => ['Action_Task' => 'First.\nSecond.', 'Card_ID' => 'X']]);

        $this->assertSame("Pick a cozy spot.\n- Shuffle the deck.\n- Take turns.", $deck->fresh()->how_to_play);
        $this->assertSame("Line one.\nLine two.", $deck->fresh()->faqs[0]['answer']);
        $this->assertSame("First.\nSecond.", $card->fresh()->content_payload['Action_Task']);
        $this->assertSame("Already\nfine.", Deck::factory()->create(['whats_inside' => "Already\nfine."])->whats_inside);
    }

    public function test_repair_command_fixes_existing_content(): void
    {
        $deck = Deck::factory()->create(['deck_code' => 'DECK-001']);
        $card = Card::factory()->for($deck)->create(['card_number' => 3]);
        $page = LegalPage::create(['title' => 'Terms', 'slug' => 'terms', 'content' => 'ok']);

        // Saved before the guard existed (straight to the database).
        DB::table('decks')->where('id', $deck->id)->update(['how_to_play' => 'A\n- B\n- C', 'whats_inside' => 'Clean text.']);
        DB::table('cards')->where('id', $card->id)->update(['content_payload' => json_encode(['Action_Task' => 'One\nTwo'])]);
        DB::table('legal_pages')->where('id', $page->id)->update(['content' => 'Para\n\nPara']);

        $this->artisan('content:fix-line-breaks --dry-run')->expectsOutputToContain('3 record(s) would be fixed')->assertSuccessful();
        $this->assertSame('A\n- B\n- C', DB::table('decks')->where('id', $deck->id)->value('how_to_play'));

        $this->artisan('content:fix-line-breaks')
            ->expectsOutputToContain('Deck DECK-001')
            ->expectsOutputToContain('DECK-001 card 3')
            ->expectsOutputToContain('3 record(s) fixed.')
            ->assertSuccessful();

        $this->assertSame("A\n- B\n- C", $deck->fresh()->how_to_play);
        $this->assertSame('Clean text.', $deck->fresh()->whats_inside);
        $this->assertSame("One\nTwo", $card->fresh()->content_payload['Action_Task']);
        $this->assertSame("Para\n\nPara", $page->fresh()->content);

        $this->artisan('content:fix-line-breaks')->expectsOutputToContain('Nothing to fix.')->assertSuccessful();
    }
}
