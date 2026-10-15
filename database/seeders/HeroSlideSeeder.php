<?php

namespace Database\Seeders;

use App\Models\HeroSlide;
use Illuminate\Database\Seeder;

/**
 * Starter hero slides, editable in the admin (Homepage → Hero Slider). Runs only while the
 * slider is empty, so it never duplicates slides or brings back ones an admin deleted.
 *   php artisan db:seed --class=HeroSlideSeeder --force
 */
class HeroSlideSeeder extends Seeder
{
    /** [eyebrow, title, subtitle, CTA text, CTA link] */
    public const SLIDES = [
        ['Conversation card decks for couples', 'Intimacy, by design.', 'Fifty cards to slow the evening down: scenes to set, questions to ask, invitations to accept — or gently decline.', 'Explore the decks', '/decks'],
        ['Digital Decks', 'Uncover Unfiltered Desires', 'Dive into our exclusive digital card decks designed for deep connection and provocative gameplay.', 'Explore Decks', '/decks'],
        ['Store Products', 'Sensual Luxury & Apparel', 'Discover our curated collection of luxury lingerie, BDSM tools, and adult accessories.', 'Shop Collection', '/store'],
        ['Physical Boxed Games', 'Premium Printed Game Boxes', 'Tactile elegance for intimate nights. Experience our physical luxury boxed game sets.', 'Order Boxed Games', '/store/printed-game-boxes'],
    ];

    public function run(): void
    {
        if (HeroSlide::query()->exists()) {
            return;
        }

        foreach (self::SLIDES as $i => [$eyebrow, $title, $subtitle, $cta, $url]) {
            HeroSlide::create([
                'eyebrow' => $eyebrow,
                'title' => $title,
                'subtitle' => $subtitle,
                'target_type' => HeroSlide::TARGET_CUSTOM,
                'custom_url' => $url,
                'cta_label' => $cta,
                'sort_order' => $i + 1,
                'is_active' => true,
            ]);
        }
    }
}
