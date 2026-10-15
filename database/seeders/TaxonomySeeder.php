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
    /** Store departments. "Printed Game Boxes" is the is_boxed_game flag, not a category. */
    public const PRODUCT_CATEGORIES = [
        'dice' => 'Dice',
        'lingerie-underwear' => 'Lingerie & Underwear',
        'apparel-outerwear' => 'Apparel & Outerwear',
        'heels' => 'Heels',
        'sex-toys' => 'Sex Toys',
        'bdsm-tools-costumes' => 'BDSM Tools & Costumes',
    ];

    /** Fantasy / content tags for decks. */
    public const DECK_CATEGORIES = [
        'bdsm' => 'BDSM',
        'roleplay' => 'Roleplay',
        'sensory' => 'Sensory',
        'dominant-submissive' => 'Dominant / Submissive',
    ];

    public function run(): void
    {
        foreach (array_keys(self::PRODUCT_CATEGORIES) as $i => $slug) {
            ProductCategory::firstOrCreate(['slug' => $slug], ['name' => self::PRODUCT_CATEGORIES[$slug], 'sort_order' => ($i + 1) * 10]);
        }

        foreach (array_keys(self::DECK_CATEGORIES) as $i => $slug) {
            DeckCategory::firstOrCreate(['slug' => $slug], ['name' => self::DECK_CATEGORIES[$slug], 'sort_order' => ($i + 1) * 10]);
        }

        $this->command?->info('Categories ready: '.ProductCategory::count().' store, '.DeckCategory::count().' deck.');
    }
}
