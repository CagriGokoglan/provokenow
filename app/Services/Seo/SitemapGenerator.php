<?php

namespace App\Services\Seo;

use App\Enums\DeckSegment;
use App\Models\Deck;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\FrontendUrl;
use Illuminate\Support\Str;
use XMLWriter;

/** sitemaps.org XML for the public site: static pages, active decks and active products only. */
class SitemapGenerator
{
    public function generate(): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        foreach ($this->entries() as $entry) {
            if (! $this->isAllowed($entry['loc'])) {
                continue;
            }

            $xml->startElement('url');
            $xml->writeElement('loc', $entry['loc']);
            if ($entry['lastmod'] ?? null) {
                $xml->writeElement('lastmod', $entry['lastmod']);
            }
            $xml->writeElement('changefreq', $entry['changefreq']);
            $xml->writeElement('priority', $entry['priority']);
            if ($entry['image'] ?? null) {
                $xml->startElement('image:image');
                $xml->writeElement('image:loc', $entry['image']);
                $xml->endElement();
            }
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /** @return iterable<array{loc: string, changefreq: string, priority: string, lastmod?: ?string, image?: ?string}> */
    private function entries(): iterable
    {
        foreach (config('provoke.seo.static_pages') as [$path, $changefreq, $priority]) {
            yield ['loc' => FrontendUrl::to($path), 'changefreq' => $changefreq, 'priority' => $priority];
        }

        // Advanced / Taboo are members-only (+18 Passion mode): never in the sitemap.
        foreach (DeckSegment::visibleTo(false) as $level) {
            yield ['loc' => FrontendUrl::deckLevel($level), 'changefreq' => 'daily', 'priority' => '0.8'];
        }

        foreach (ProductCategory::active()->orderBy('sort_order')->cursor() as $category) {
            yield [
                'loc' => FrontendUrl::storeCategory($category),
                'lastmod' => $category->updated_at?->toAtomString(),
                'changefreq' => 'daily',
                'priority' => '0.8',
            ];
        }

        foreach (Post::published()->orderByDesc('published_at')->cursor() as $post) {
            yield [
                'loc' => FrontendUrl::post($post),
                'lastmod' => $post->updated_at?->toAtomString(),
                'changefreq' => 'monthly',
                'priority' => $post->is_pillar ? '0.8' : '0.6',
                'image' => $post->featuredImageUrl(),
            ];
        }

        foreach (Deck::active()->whereIn('segment', DeckSegment::visibleTo(false))->orderBy('deck_code')->cursor() as $deck) {
            yield [
                'loc' => $deck->canonical_url ?: FrontendUrl::deck($deck),
                'lastmod' => $deck->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'image' => $deck->ogImageUrl(),
            ];
        }

        // Digital editions are reached through their deck page.
        foreach (Product::storefront()->visibleTo(false)->orderBy('slug')->cursor() as $product) {
            yield [
                'loc' => $product->canonical_url ?: FrontendUrl::product($product),
                'lastmod' => $product->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.7',
                'image' => $product->ogImageUrl() ?: ($product->images()[0] ?? null),
            ];
        }
    }

    /** Only URLs on the public site, and never private sections. */
    private function isAllowed(string $url): bool
    {
        $base = config('provoke.frontend.url');
        if (! Str::startsWith($url, $base)) {
            return false;
        }

        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach (config('provoke.seo.sitemap_disallowed_prefixes') as $prefix) {
            if ($path === $prefix || Str::startsWith($path, rtrim($prefix, '/').'/')) {
                return false;
            }
        }

        return true;
    }
}
