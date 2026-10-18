<?php

namespace App\Services\Seo;

use App\Models\Deck;
use App\Models\Product;
use App\Models\SiteSetting;

/**
 * FAQ lists for deck and product pages: the item's own questions (admin), or the site-wide
 * defaults (Site Settings → Default FAQs) when it has none. Same list on the page and in schema.
 */
class Faqs
{
    public const DECK_DEFAULTS = 'default_deck_faqs';

    public const PRODUCT_DEFAULTS = 'default_product_faqs';

    /** @return list<array{question: string, answer: string}> */
    public function forDeck(Deck $deck): array
    {
        return $this->clean($deck->faqs) ?: $this->clean(SiteSetting::value(self::DECK_DEFAULTS));
    }

    /** @return list<array{question: string, answer: string}> */
    public function forProduct(Product $product): array
    {
        return $this->clean($product->faqs) ?: $this->clean(SiteSetting::value(self::PRODUCT_DEFAULTS));
    }

    /** @return list<array{question: string, answer: string}> */
    public function clean(mixed $faqs): array
    {
        return collect((array) $faqs)
            ->filter(fn ($f) => is_array($f) && filled($f['question'] ?? null) && filled($f['answer'] ?? null))
            ->map(fn (array $f) => ['question' => trim((string) $f['question']), 'answer' => trim((string) $f['answer'])])
            ->values()
            ->all();
    }
}
