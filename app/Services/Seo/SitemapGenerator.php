<?php

namespace App\Services\Seo;

use App\Enums\DeckSegment;
use App\Models\Deck;
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

        foreach (DeckSegment::cases() as $level) {
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

        foreach (Deck::active()->orderBy('deck_code')->cursor() as $deck) {
            yield [
                'loc' => $deck->canonical_url ?: FrontendUrl::deck($deck),
                'lastmod' => $deck->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'image' => $deck->ogImageUrl(),
            ];
        }

        foreach (Product::active()->orderBy('slug')->cursor() as $product) {
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
