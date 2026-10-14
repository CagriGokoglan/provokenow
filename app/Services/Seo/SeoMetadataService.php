<?php

namespace App\Services\Seo;

use App\Models\Deck;
use App\Models\Product;
use App\Support\FrontendUrl;

/**
 * Builds the `seo` object the Next.js frontend turns into <title>, meta, canonical and
 * Open Graph / Twitter tags. Editorial values win; everything has a sensible fallback.
 */
class SeoMetadataService
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 155;

    public function forDeck(Deck $deck): array
    {
        $brand = config('provoke.brand.name');
        $keywords = implode(', ', array_slice($deck->themeKeywords(), 0, 4));

        return $this->format(
            title: $deck->meta_title ?: "{$deck->title} | {$brand}",
            description: $deck->meta_description
                ?: "{$deck->title}: a {$deck->segment->label()} card deck for couples from {$brand}."
                    .($keywords !== '' ? " Themes: {$keywords}." : ''),
            canonical: $deck->canonical_url ?: FrontendUrl::deck($deck),
            image: $deck->og_image,
            type: 'product',
        );
    }

    public function forProduct(Product $product): array
    {
        $brand = config('provoke.brand.name');

        return $this->format(
            title: $product->meta_title ?: "{$product->name} | {$brand}",
            description: $product->meta_description ?: ($product->description ?: "{$product->name} from {$brand}."),
            canonical: $product->canonical_url ?: FrontendUrl::product($product),
            image: $product->og_image ?: ($product->images()[0] ?? null),
            type: 'product',
        );
    }

    private function format(string $title, string $description, string $canonical, ?string $image, string $type): array
    {
        $title = $this->fit($title, self::TITLE_MAX);
        $description = $this->fit($description, self::DESCRIPTION_MAX);
        $image ??= config('provoke.seo.default_og_image');
        $siteName = config('provoke.brand.name');

        return [
            'title' => $title,
            'description' => $description,
            'canonical_url' => $canonical,
            'robots' => 'index,follow',
            'og' => [
                'type' => $type,
                'site_name' => $siteName,
                'title' => $title,
                'description' => $description,
                'url' => $canonical,
                'image' => $image,
                'locale' => 'en_US',
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'title' => $title,
                'description' => $description,
                'image' => $image,
            ],
        ];
    }

    /** Trim to $max characters on a word boundary, adding an ellipsis when cut. */
    private function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:-|').'…';
    }
}
