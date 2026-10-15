<?php

namespace App\Support;

use App\Enums\DeckSegment;
use App\Models\Deck;
use App\Models\Product;
use App\Models\ProductCategory;

/** Absolute URLs on the public Next.js site (provoke.frontend). */
final class FrontendUrl
{
    public static function to(string $path = '/'): string
    {
        return config('provoke.frontend.url').'/'.ltrim($path, '/');
    }

    public static function deck(Deck $deck): string
    {
        return self::path('deck', ['{code}' => strtolower($deck->deck_code)]);
    }

    public static function product(Product $product): string
    {
        return self::path('product', ['{slug}' => $product->slug]);
    }

    /** Signed-in reading view for an owned deck (used in emails; never in the sitemap). */
    public static function libraryDeck(Deck $deck): string
    {
        return self::path('library_deck', ['{code}' => strtolower($deck->deck_code)]);
    }

    public static function deckLevel(DeckSegment $level): string
    {
        return self::path('deck_level', ['{level}' => $level->slug()]);
    }

    public static function storeCategory(ProductCategory $category): string
    {
        return self::path('store_category', ['{slug}' => $category->slug]);
    }

    /** @param array<string, string> $replace */
    private static function path(string $key, array $replace): string
    {
        return self::to(strtr(config("provoke.frontend.paths.{$key}"), array_map('rawurlencode', $replace)));
    }
}
