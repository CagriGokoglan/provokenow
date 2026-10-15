<?php

namespace Database\Seeders;

use App\Enums\DeckSegment;
use App\Models\Deck;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the catalogue skeleton DECK-001 … DECK-100 (DECK_COUNT), each with 50 placeholder cards.
 *
 * Idempotent: re-running updates deck metadata in place and never overwrites card
 * content that has since been replaced with real copy.
 */
class DeckSampleSeeder extends Seeder
{
    public const DECK_COUNT = 100;

    public const CARDS_PER_DECK = 50;

    public function run(): void
    {
        DB::transaction(function () {
            for ($n = 1; $n <= self::DECK_COUNT; $n++) {
                $code = sprintf('DECK-%03d', $n);
                [$segment, $intensity] = $this->tier($n);

                $deck = Deck::updateOrCreate(['deck_code' => $code], [
                    'title' => "Deck {$n} — Title TBD",
                    'segment' => $segment,
                    'intensity_level' => $intensity,
                    'core_theme_keywords' => 'placeholder, theme, keywords',
                    'ai_system_role_prompt' => "You are the in-app guide for {$code}. Keep every suggestion consensual, "
                        .'respectful and aligned with the deck\'s intensity level. [Placeholder — replace with final prompt]',
                    'pod_card_box_theme' => Str::slug($segment->value).'-box',
                    'digital_delivery_file_name' => "{$code}.pdf",
                    'is_active' => false, // Activate once real content is loaded.
                ]);

                $this->seedCards($deck);
            }
        });

        $this->command?->info(sprintf('Seeded %d decks × %d cards.', self::DECK_COUNT, self::CARDS_PER_DECK));
    }

    /**
     * Placeholder split, proportional to DECK_COUNT: first 30% Light Ignition (1–2),
     * next 40% Medium Spice (2–3), last 30% Advanced Taboo (3–4). Real values are set per deck.
     *
     * @return array{DeckSegment, int}
     */
    private function tier(int $n): array
    {
        $p = $n / self::DECK_COUNT;

        return match (true) {
            $p <= 0.30 => [DeckSegment::LightIgnition, $p <= 0.15 ? 1 : 2],
            $p <= 0.70 => [DeckSegment::MediumSpice, $p <= 0.50 ? 2 : 3],
            default => [DeckSegment::AdvancedTaboo, $p <= 0.85 ? 3 : 4],
        };
    }

    private function seedCards(Deck $deck): void
    {
        $now = now();
        $rows = [];

        for ($c = 1; $c <= self::CARDS_PER_DECK; $c++) {
            $cardId = sprintf('%s-C%02d', $deck->deck_code, $c);
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'deck_id' => $deck->id,
                'card_number' => $c,
                'title' => "Card {$c}",
                'content_payload' => json_encode([
                    'Card_ID' => $cardId,
                    'Title' => "Card {$c}",
                    'Setting_and_Scenario' => '[Placeholder]',
                    'Action_Task' => '[Placeholder]',
                    'Safety_and_Consent_Note' => '[Placeholder]',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Insert missing cards only; existing rows (possibly with real content) are left untouched.
        DB::table('cards')->insertOrIgnore($rows);
    }
}
