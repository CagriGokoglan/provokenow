<?php

namespace Database\Seeders;

use App\Enums\DeckSegment;
use App\Enums\ProductType;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckCategory;
use App\Models\HeroSlide;
use App\Models\InstagramPost;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
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
        ['DECK-040', 'Behind Closed Doors', DeckSegment::Advanced, 3, 'fantasy, boundaries, negotiation'],
        ['DECK-041', 'The Ledger', DeckSegment::Advanced, 4, 'dares, power, aftercare'],
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

        $this->seedHomepage($decks);

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

    /** @param array<string, Deck> $decks */
    private function seedHomepage(array $decks): void
    {
        $this->call(TaxonomySeeder::class);

        $tags = DeckCategory::pluck('id', 'slug');
        $decks['DECK-001']->categories()->syncWithoutDetaching([$tags['sensory']]);
        $decks['DECK-020']->categories()->syncWithoutDetaching([$tags['roleplay'], $tags['sensory']]);
        $decks['DECK-021']->categories()->syncWithoutDetaching([$tags['roleplay'], $tags['dominant-submissive']]);
        $decks['DECK-040']->categories()->syncWithoutDetaching([$tags['bdsm'], $tags['dominant-submissive']]);
        $decks['DECK-041']->update(['segment' => DeckSegment::Taboo, 'intensity_level' => 4]);
        $decks['DECK-041']->categories()->syncWithoutDetaching([$tags['bdsm']]);

        $cats = ProductCategory::pluck('id', 'slug');
        Product::where('slug', 'silk-blindfold')->update(['product_category_id' => $cats['bdsm-tools-costumes'], 'is_featured' => true, 'is_new_arrival' => true]);
        Product::where('slug', 'massage-candle')->update(['product_category_id' => $cats['sex-toys'], 'is_featured' => true]);
        Product::where('slug', 'like', 'box-%')->update(['is_boxed_game' => true, 'is_featured' => true, 'product_category_id' => $cats['printed-game-boxes']]);
        Product::where('slug', 'like', 'dig-%')->orWhere('slug', 'first-sparks-trilogy')->update(['product_category_id' => $cats['card-games']]);
        ProductCategory::whereIn('slug', ['card-games', 'printed-game-boxes', 'lingerie-underwear', 'bdsm-tools-costumes'])->update(['show_in_footer' => true]);
        foreach ([['velvet-dice', 'Velvet Dice', 'dice', 1800], ['lace-bodysuit', 'Lace Bodysuit', 'lingerie-underwear', 8900], ['patent-stilettos', 'Patent Stilettos', 'heels', 14500], ['satin-robe', 'Satin Robe', 'apparel-outerwear', 11000]] as [$slug, $name, $cat, $price]) {
            $p = $this->product($slug, $name, ProductType::PhysicalAccessory, $price, "Sample {$name} for local development.", 20);
            $p->update(['product_category_id' => $cats[$cat], 'is_new_arrival' => true]);
        }

        $disk = Storage::disk('public');
        $art = fn (string $path, int $w, int $h, array $from, array $to) => $disk->exists($path) ? $path : tap($path, fn () => $disk->put($path, $this->gradientJpeg($w, $h, $from, $to)));

        HeroSlide::query()->delete();
        HeroSlide::create(['eyebrow' => 'New deck', 'title' => 'After Midnight', 'subtitle' => 'Secrets, candlelight and daring honesty.', 'image' => $art('home/demo/hero-1.jpg', 2400, 1200, [58, 18, 44], [12, 6, 10]), 'target_type' => 'deck', 'deck_id' => $decks['DECK-020']->id, 'sort_order' => 1]);
        HeroSlide::create(['eyebrow' => 'Printed & boxed', 'title' => 'Made to be held', 'subtitle' => 'Our decks, printed on heavy stock.', 'image' => $art('home/demo/hero-2.jpg', 2400, 1200, [92, 30, 40], [18, 8, 14]), 'target_type' => 'product', 'product_id' => Product::firstWhere('slug', 'box-deck-020')->id, 'sort_order' => 2]);
        HeroSlide::create(['title' => 'The Store', 'subtitle' => 'Dice, lingerie, heels and more.', 'image' => $art('home/demo/hero-3.jpg', 2400, 1200, [40, 28, 18], [10, 6, 8]), 'target_type' => 'custom', 'custom_url' => '/store', 'cta_label' => 'Shop Now', 'sort_order' => 3]);

        InstagramPost::query()->delete();
        foreach (range(1, 3) as $i) {
            InstagramPost::create(['post_url' => "https://www.instagram.com/p/DEMO{$i}/", 'image' => $art("home/demo/ig-{$i}.jpg", 1080, 1080, [60 + $i * 20, 20, 40], [14, 6, 12]), 'caption' => "Sample post {$i}", 'sort_order' => $i]);
        }

        SiteSetting::put('instagram', ['handle' => 'provokenow', 'profile_url' => 'https://www.instagram.com/']);
        SiteSetting::put('club', ['enabled' => true]);
        SiteSetting::put('brand_story', 'Conversation and connection, set in card stock and candlelight.');
    }

    /** Placeholder artwork so local pages have something to render (no stock imagery). */
    private function gradientJpeg(int $w, int $h, array $from, array $to): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $color = imagecolorallocate($img, ...array_map(fn ($a, $b) => (int) ($a + ($b - $a) * $t), $from, $to));
            imageline($img, 0, $y, $w, $y, $color);
        }
        ob_start();
        imagejpeg($img, null, 82);

        return (string) ob_get_clean();
    }

    private static function roman(int $n): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII'][$n] ?? (string) $n;
    }
}
