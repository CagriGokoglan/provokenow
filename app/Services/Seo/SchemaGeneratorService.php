<?php

namespace App\Services\Seo;

use App\Enums\ProductType;
use App\Models\Card;
use App\Models\Deck;
use App\Models\Post;
use App\Models\Product;
use App\Support\FrontendUrl;
use Illuminate\Support\Collection;

/**
 * Schema.org JSON-LD for search and answer engines. Every page payload is a single
 * {"@context", "@graph"} document the frontend drops into <script type="application/ld+json">.
 *
 * Paid card content is never emitted: deck FAQs use deck facts, curated Q&A and the public
 * preview card only.
 */
class SchemaGeneratorService
{
    private const CONTEXT = 'https://schema.org';

    /** @param list<array<string, mixed>> $nodes */
    public function graph(array $nodes): array
    {
        return ['@context' => self::CONTEXT, '@graph' => array_values(array_filter($nodes))];
    }

    public function organization(): array
    {
        $brand = config('provoke.brand');

        return array_filter([
            '@type' => 'Organization',
            '@id' => $this->organizationId(),
            'name' => $brand['name'],
            'legalName' => $brand['legal_name'],
            'url' => FrontendUrl::to('/'),
            'logo' => $brand['logo_url'],
            'description' => $brand['description'],
            'sameAs' => $brand['same_as'] ?: null,
        ]);
    }

    public function product(Product $product): array
    {
        $url = $product->canonical_url ?: FrontendUrl::product($product);
        $images = $product->images() ?: array_filter([$product->ogImageUrl()]);

        return array_filter([
            '@type' => 'Product',
            '@id' => "{$url}#product",
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->meta_description ?: $product->description,
            'image' => $images ?: null,
            'url' => $url,
            'category' => $this->categoryFor($product->product_type),
            'brand' => ['@type' => 'Brand', 'name' => config('provoke.brand.name')],
            'offers' => array_filter([
                '@type' => 'Offer',
                'url' => $url,
                'priceCurrency' => config('provoke.commerce.currency'),
                'price' => number_format($product->effectivePriceCents() / 100, 2, '.', ''),
                'availability' => $product->isPurchasable() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => ['@id' => $this->organizationId()],
                // Shipping is complimentary on every order.
                'shippingDetails' => $product->requiresShipping() ? [
                    '@type' => 'OfferShippingDetails',
                    'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => '0', 'currency' => config('provoke.commerce.currency')],
                    'shippingLabel' => config('provoke.commerce.shipping_label'),
                ] : null,
            ]),
        ], fn ($v) => $v !== null);
    }

    /** @param list<array{0: string, 1: string}> $crumbs [name, absolute url] pairs */
    public function breadcrumbs(array $crumbs): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $crumb, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb[0],
                'item' => $crumb[1],
            ], $crumbs, array_keys($crumbs)),
        ];
    }

    /** @param list<array{question: string, answer: string}> $faqs */
    public function faqPage(array $faqs, string $url): ?array
    {
        if ($faqs === []) {
            return null;
        }

        return [
            '@type' => 'FAQPage',
            '@id' => "{$url}#faq",
            'mainEntity' => array_map(fn (array $faq) => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
            ], $faqs),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products  active products that claim this deck
     */
    public function forDeck(Deck $deck, ?Card $previewCard, int $cardCount, Collection $products): array
    {
        $url = $deck->canonical_url ?: FrontendUrl::deck($deck);

        return $this->graph([
            $this->breadcrumbs([
                ['Home', FrontendUrl::to('/')],
                ['Decks', FrontendUrl::to('/decks')],
                [$deck->title, $url],
            ]),
            $this->faqPage($this->deckFaqs($deck, $previewCard, $cardCount, $products), $url),
            ...$products->map(fn (Product $p) => $this->product($p))->all(),
        ]);
    }

    public function forProduct(Product $product): array
    {
        $url = $product->canonical_url ?: FrontendUrl::product($product);

        return $this->graph([
            $this->product($product),
            $this->breadcrumbs([
                ['Home', FrontendUrl::to('/')],
                ['Store', FrontendUrl::to('/store')],
                [$product->name, $url],
            ]),
            $this->faqPage(app(Faqs::class)->forProduct($product), $url),
        ]);
    }

    /** BlogPosting + breadcrumbs + FAQPage for a Journal post. */
    public function forPost(Post $post, array $faqs): array
    {
        $url = FrontendUrl::post($post);
        $author = $post->author_name
            ? array_filter(['@type' => 'Person', 'name' => $post->author_name, 'jobTitle' => $post->author_role])
            : ['@id' => $this->organizationId()];

        return $this->graph([
            array_filter([
                '@type' => 'BlogPosting',
                '@id' => "{$url}#article",
                'headline' => mb_substr($post->title, 0, 110),
                'description' => $post->seo_description ?: $post->excerpt,
                'image' => $post->featuredImageUrl() ? [$post->featuredImageUrl()] : null,
                'datePublished' => $post->published_at?->toAtomString(),
                'dateModified' => $post->updated_at?->toAtomString(),
                'author' => $author,
                'publisher' => ['@id' => $this->organizationId()],
                'mainEntityOfPage' => $url,
                'articleSection' => $post->category?->name,
                'inLanguage' => 'en',
            ], fn ($v) => $v !== null),
            $this->breadcrumbs([
                ['Home', FrontendUrl::to('/')],
                ['Journal', FrontendUrl::to('/blog')],
                [$post->title, $url],
            ]),
            $this->faqPage($faqs, $url),
        ]);
    }

    /** The site itself (sits next to Organization on every page). */
    public function website(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => FrontendUrl::to('/').'#website',
            'url' => FrontendUrl::to('/'),
            'name' => config('provoke.brand.name'),
            'inLanguage' => 'en',
            'publisher' => ['@id' => $this->organizationId()],
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{question: string, answer: string}>
     */
    public function deckFaqs(Deck $deck, ?Card $previewCard, int $cardCount, Collection $products): array
    {
        $brand = config('provoke.brand.name');
        $keywords = implode(', ', $deck->themeKeywords());

        $faqs = app(Faqs::class)->forDeck($deck);

        $faqs[] = [
            'question' => "What is {$deck->title}?",
            'answer' => "{$deck->title} is a {$cardCount}-card {$deck->segment->label()} deck for couples by {$brand}, "
                ."rated {$deck->intensity_level} out of 4 for intensity."
                .($keywords !== '' ? " It explores {$keywords}." : ''),
        ];

        $faqs[] = [
            'question' => "Who is {$deck->title} for?",
            'answer' => $deck->segment->audience(),
        ];

        if ($products->contains(fn (Product $p) => $p->product_type === ProductType::PodPhysicalDeck)) {
            $faqs[] = [
                'question' => "Is {$deck->title} available as a physical deck?",
                'answer' => "Yes. {$deck->title} is available as a printed box edition as well as an instant digital edition.",
            ];
        }

        if ($previewCard && ($sample = $this->previewText($previewCard))) {
            $faqs[] = [
                'question' => "What does a card from {$deck->title} look like?",
                'answer' => "Here is the free preview card, \"{$previewCard->title}\": {$sample}",
            ];
        }

        return $faqs;
    }

    private function previewText(Card $card): ?string
    {
        $payload = (array) $card->content_payload;
        $text = trim(implode(' ', array_filter([
            $payload['Setting_and_Scenario'] ?? null,
            $payload['Action_Task'] ?? null,
        ], 'is_string')));

        return $text === '' || str_contains($text, '[Placeholder]') ? null : $text;
    }

    private function categoryFor(ProductType $type): string
    {
        return match ($type) {
            ProductType::DigitalDeck => 'Digital Card Deck',
            ProductType::PodPhysicalDeck => 'Card Deck',
            ProductType::PhysicalAccessory => 'Intimacy Accessory',
            ProductType::Bundle => 'Bundle',
        };
    }

    private function organizationId(): string
    {
        return FrontendUrl::to('/').'#organization';
    }
}
