<?php

namespace App\Services\Seo;

use App\Models\Deck;
use App\Models\Post;
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

    /** Descriptions shorter than this get a conversion trigger appended (target 140–155). */
    public const DESCRIPTION_MIN = 140;

    public function forDeck(Deck $deck): array
    {
        $brand = config('provoke.brand.name');
        $keywords = implode(', ', array_slice($deck->themeKeywords(), 0, 4));

        return $this->format(
            title: $deck->meta_title ?: $this->branded("{$deck->title}: Card Game for Couples"),
            description: $deck->meta_description
                ?: $this->describe(
                    "{$deck->title} is a {$deck->segment->label()} relationship card game for couples: deep questions and conversation starters for date night."
                        .($keywords !== '' ? " Themes: {$keywords}." : ''),
                    ['Instant digital access.', 'Play in your browser.'],
                ),
            canonical: $deck->canonical_url ?: FrontendUrl::deck($deck),
            image: $deck->ogImageUrl(),
            type: 'product',
        );
    }

    public function forProduct(Product $product): array
    {
        $brand = config('provoke.brand.name');

        return $this->format(
            // Name first, then its category keyword ("… – Card Game for Couples", "… – Lingerie").
            title: $product->meta_title ?: $this->brandedWithKeyword(
                $product->name,
                $product->decks->isNotEmpty() ? 'Card Game for Couples' : $product->category?->name,
            ),
            description: $product->meta_description ?: $this->describe(
                $product->description ?: "{$product->name}, chosen by {$brand} for intimate evenings and date nights.",
                $product->requiresShipping()
                    ? ['Free discreet shipping in unbranded packaging.', 'Ships free in discreet packaging.', 'Free discreet shipping.']
                    : ['Instant digital access.', 'Play tonight.'],
            ),
            canonical: $product->canonical_url ?: FrontendUrl::product($product),
            image: $product->ogImageUrl() ?: ($product->images()[0] ?? null),
            type: 'product',
        );
    }

    public function forPost(Post $post): array
    {
        return $this->format(
            title: $post->seo_title ?: $this->branded($post->title),
            description: $post->seo_description ?: $this->describe((string) ($post->excerpt ?: $post->tldr ?: $post->title), ['Read more on the PROVOKE NOW Journal.']),
            canonical: FrontendUrl::post($post),
            image: $post->featuredImageUrl(),
            type: 'article',
        );
    }

    /** "Primary keyword first … | PROVOKE NOW", shortening the keyword part (never the brand) to fit 60. */
    public function branded(string $primary): string
    {
        $suffix = ' | '.config('provoke.brand.name');

        return $this->fit($primary, self::TITLE_MAX - mb_strlen($suffix)).$suffix;
    }

    /** "Name – Keyword | Brand" when it fits in 60 characters, otherwise just "Name | Brand" (no half keywords). */
    public function brandedWithKeyword(string $name, ?string $keyword): string
    {
        $suffix = ' | '.config('provoke.brand.name');
        $full = $keyword ? "{$name} – {$keyword}" : $name;

        return mb_strlen($full.$suffix) <= self::TITLE_MAX ? $full.$suffix : $this->branded($name);
    }

    /** Tops a short description up with conversion triggers, staying within 155 characters. */
    public function describe(string $text, array $triggers = []): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
        foreach ($triggers as $trigger) {
            if (mb_strlen($text) >= self::DESCRIPTION_MIN) {
                break;
            }
            if (mb_strlen($text) + 1 + mb_strlen($trigger) <= self::DESCRIPTION_MAX) {
                $text = rtrim($text, ' ').' '.$trigger;
            }
        }

        return $text;
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
