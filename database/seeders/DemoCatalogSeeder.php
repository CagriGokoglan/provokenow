<?php

namespace Database\Seeders;

use App\Enums\DeckSegment;
use App\Enums\ProductType;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * LOCAL DEVELOPMENT ONLY — a small, presentable catalogue for building the web app.
 *   php artisan db:seed --class=DemoCatalogSeeder
 * Copy is sample text; replace with real deck content before launch.
 */
class DemoCatalogSeeder extends Seeder
{
    private const DECKS = [
        ['DECK-001', 'First Light', DeckSegment::LightIgnition, 1, 'curiosity, laughter, first questions'],
        ['DECK-002', 'Slow Burn', DeckSegment::LightIgnition, 2, 'anticipation, touch, patience'],
        ['DECK-020', 'After Midnight', DeckSegment::MediumSpice, 2, 'secrets, candlelight, daring honesty'],
        ['DECK-021', 'Velvet Rope', DeckSegment::MediumSpice, 3, 'play, trust, role reversal'],
        ['DECK-040', 'Behind Closed Doors', DeckSegment::AdvancedTaboo, 3, 'fantasy, boundaries, negotiation'],
        ['DECK-041', 'The Ledger', DeckSegment::AdvancedTaboo, 4, 'dares, power, aftercare'],
    ];

    private const SETTINGS = [
        'Dim every light but one.', 'Sit facing each other, knees touching.', 'Pour two glasses of something you both love.',
        'Put your phones in another room.', 'Choose a song neither of you has heard in years.', 'Sit back to back on the floor.',
        'Light a candle and let it set the pace.', 'Wrap yourselves in the same blanket.',
    ];

    private const ACTIONS = [
        'Tell your partner the exact moment you knew you wanted to kiss them.',
        'Describe a memory of the two of you using only three words. Let them guess the rest.',
        'Hold eye contact for sixty seconds without speaking. Then say the first thing you noticed.',
        'Ask one question you have always wanted to ask, and promise to answer theirs in return.',
        'Trade one thing you would love more of, and one thing you would love to try.',
        'Whisper a compliment you have never said out loud.',
        'Plan, in detail, an evening you would like to share next month.',
        'Name a place on their skin you would like to know better. Ask before you touch.',
    ];

    private const SAFETY = [
        'Either of you can pass on any card, no explanation needed.',
        'Check in halfway: still good? Change course anytime.',
        'Agree on a word that means "pause" before you begin.',
        'Go only as far as you both say yes to.',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCatalogSeeder must not run in production.');
        }

        $decks = [];
        foreach (self::DECKS as [$code, $title, $segment, $intensity, $themes]) {
            $decks[$code] = $deck = Deck::updateOrCreate(['deck_code' => $code], [
                'title' => $title,
                'segment' => $segment,
                'intensity_level' => $intensity,
                'core_theme_keywords' => $themes,
                'pod_card_box_theme' => 'velvet-gold',
                'digital_delivery_file_name' => "{$code}.pdf",
                'is_active' => true,
            ]);

            for ($n = 1; $n <= 50; $n++) {
                $i = $n + strlen($code);
                Card::updateOrCreate(['deck_id' => $deck->id, 'card_number' => $n], [
                    'title' => ['The Invitation', 'Slow Reveal', 'Say It Softly', 'Closer', 'The Question', 'Unhurried', 'Your Turn', 'Afterglow'][$n % 8].($n > 8 ? ' '.self::roman(intdiv($n - 1, 8) + 1) : ''),
                    'content_payload' => [
                        'Card_ID' => sprintf('%s-C%02d', $code, $n),
                        'Title' => null,
                        'Setting_and_Scenario' => self::SETTINGS[$i % count(self::SETTINGS)],
                        'Action_Task' => self::ACTIONS[($i * 3) % count(self::ACTIONS)],
                        'Safety_and_Consent_Note' => self::SAFETY[$n % count(self::SAFETY)],
                    ],
                ]);
            }
            $deck->cards()->get()->each(fn (Card $c) => $c->update(['content_payload' => [...$c->content_payload, 'Title' => $c->title]]));
        }

        foreach ($decks as $code => $deck) {
            $digital = $this->product("dig-{$code}", "{$deck->title} — Digital Deck", ProductType::DigitalDeck, 1900, "All 50 cards of {$deck->title}, unlocked instantly in your private library.");
            $digital->decks()->syncWithoutDetaching([$deck->id]);
        }

        foreach (['DECK-001', 'DECK-020', 'DECK-040'] as $code) {
            $pod = $this->product("box-{$code}", "{$decks[$code]->title} — The Box", ProductType::PodPhysicalDeck, 3900, 'Printed on heavyweight matte stock with gilded edges, presented in a rigid keepsake box.');
            $pod->decks()->syncWithoutDetaching([$decks[$code]->id]);
        }

        $this->product('silk-blindfold', 'Silk Blindfold', ProductType::PhysicalAccessory, 3400, '22-momme mulberry silk with a soft elastic band.', 40, ['segments' => ['Medium_Spice', 'Advanced_Taboo']]);
        $this->product('massage-candle', 'Massage Candle', ProductType::PhysicalAccessory, 2800, 'Soy and shea butter, warm amber and fig. Melts into a skin-safe oil.', 60, ['all_digital_decks' => true]);

        $trilogy = $this->product('first-sparks-trilogy', 'First Sparks Trilogy', ProductType::Bundle, 4500, 'Three Light Ignition decks to begin with. Digital.', -1, null, false);
        $trilogy->decks()->syncWithoutDetaching([$decks['DECK-001']->id, $decks['DECK-002']->id]);

        $this->command?->info('Demo catalogue ready: '.count($decks).' decks, '.Product::count().' products.');
    }

    private function product(string $slug, string $name, ProductType $type, int $price, string $description, int $stock = -1, ?array $upsell = null, ?bool $ships = null): Product
    {
        $slug = strtolower($slug);

        return Product::updateOrCreate(['slug' => $slug], [
            'sku' => strtoupper($slug),
            'name' => $name,
            'product_type' => $type,
            'price_cents' => $price,
            'stock_quantity' => $stock,
            'is_active' => true,
            'description' => $description,
            'metadata' => array_filter(['images' => [], 'upsell' => $upsell, 'requires_shipping' => $ships], fn ($v) => $v !== null),
        ]);
    }

    private static function roman(int $n): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII'][$n] ?? (string) $n;
    }
}
