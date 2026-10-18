<?php

namespace Database\Seeders;

use App\Models\DeckCategory;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * Starting categories. Safe in production and re-runnable: existing rows (matched by slug) are
 * left exactly as edited in the admin panel; only missing ones are created.
 */
class TaxonomySeeder extends Seeder
{
    /** Store departments (all editable in the admin panel). */
    public const PRODUCT_CATEGORIES = [
        'card-games' => 'Card Games',
        'printed-game-boxes' => 'Printed Game Boxes',
        'dice' => 'Dice',
        'lingerie-underwear' => 'Lingerie & Underwear',
        'apparel-outerwear' => 'Apparel & Outerwear',
        'heels' => 'Heels',
        'sex-toys' => 'Sex Toys',
        'bdsm-tools-costumes' => 'BDSM Tools & Costumes',
    ];

    /** Fantasy / content tags for decks (the /decks "Fantasy" filter), in display order. */
    public const DECK_CATEGORIES = [
        'sensory-touch' => 'Sensory & Touch',
        'roleplay-fantasy' => 'Roleplay & Fantasy',
        'bdsm-impact' => 'BDSM & Impact',
        'dominant-submissive' => 'Dominant / Submissive',
        'deep-talk-connection' => 'Deep Talk & Connection',
        'exhibitionism-public-play' => 'Exhibitionism & Public Play',
        'taboo-alternative' => 'Taboo & Alternative',
        'hotwife-cuckolding' => 'Hotwife & Cuckolding',
        'teasing-edging' => 'Teasing & Edging',
        'intimate-exploration-body-mapping' => 'Intimate Exploration & Body Mapping',
        'long-distance-digital' => 'Long-Distance & Digital',
        'beginner-gifting' => 'Beginner & Gifting',
        'creator-camera-play' => 'Creator & Camera Play',
        'star-persona-simulation' => 'Star & Persona Simulation',
        'taboo-performance-exhibition' => 'Taboo Performance & Exhibition',
    ];

    public function run(): void
    {
        foreach (array_keys(self::PRODUCT_CATEGORIES) as $i => $slug) {
            ProductCategory::firstOrCreate(['slug' => $slug], ['name' => self::PRODUCT_CATEGORIES[$slug], 'sort_order' => $i + 1]);
        }

        foreach (array_keys(self::DECK_CATEGORIES) as $i => $slug) {
            DeckCategory::firstOrCreate(['slug' => $slug], ['name' => self::DECK_CATEGORIES[$slug], 'sort_order' => ($i + 1) * 10]);
        }

        $this->command?->info('Categories ready: '.ProductCategory::count().' store, '.DeckCategory::count().' deck.');
    }
}
