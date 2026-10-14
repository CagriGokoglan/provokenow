<?php

namespace App\Services\Commerce;

use App\Enums\ProductType;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Bundle rules:
 *  - Digital deck  → POD physical box that claims the same deck        (bundle discount)
 *  - Digital deck  → accessory whose metadata.upsell targets the deck  (bundle discount)
 *  - Anything else → other products sharing a deck claim               (plain cross-sell)
 *
 * isBundleEligible() is the single source of truth: CartPricing re-checks it on every
 * pricing pass, so a discount disappears as soon as its anchor deck leaves the cart.
 */
class UpsellEngine
{
    public const REASON_POD_BOX = 'matching_pod_box';

    public const REASON_ACCESSORY = 'paired_accessory';

    public const REASON_RELATED = 'related_product';

    public function discountPercent(): int
    {
        return (int) config('provoke.upsell.bundle_discount_percent');
    }

    public function unitDiscountCents(Product $product): int
    {
        return intdiv($product->effectivePriceCents() * $this->discountPercent(), 100);
    }

    public function isBundleEligible(Product $anchor, Product $candidate): bool
    {
        if ($anchor->product_type !== ProductType::DigitalDeck || $anchor->is($candidate)) {
            return false;
        }

        return match ($candidate->product_type) {
            ProductType::PodPhysicalDeck => $candidate->decks->modelKeys() !== []
                && array_intersect($candidate->decks->modelKeys(), $anchor->decks->modelKeys()) !== [],
            ProductType::PhysicalAccessory => $anchor->decks->contains(fn ($deck) => $candidate->upsellTargetsDeck($deck)),
            default => false,
        };
    }

    /**
     * Discounted add-ons for a digital deck.
     *
     * @return Collection<int, array{product: Product, reason: string}>
     */
    public function bundleCandidatesFor(Product $digitalDeck): Collection
    {
        $digitalDeck->loadMissing('decks');

        if ($digitalDeck->product_type !== ProductType::DigitalDeck || $digitalDeck->decks->isEmpty()) {
            return collect();
        }

        $pods = Product::active()
            ->where('product_type', ProductType::PodPhysicalDeck->value)
            ->whereHas('decks', fn ($q) => $q->whereIn('decks.id', $digitalDeck->decks->modelKeys()))
            ->with('decks:id,deck_code,segment')
            ->orderBy('price_cents')
            ->get();

        $accessories = Product::active()
            ->where('product_type', ProductType::PhysicalAccessory->value)
            ->whereNotNull('metadata->upsell')
            ->with('decks:id,deck_code,segment')
            ->orderBy('price_cents')
            ->get();

        return $pods->concat($accessories)
            ->filter(fn (Product $p) => $p->isPurchasable() && $this->isBundleEligible($digitalDeck, $p))
            ->map(fn (Product $p) => [
                'product' => $p,
                'reason' => $p->product_type === ProductType::PodPhysicalDeck ? self::REASON_POD_BOX : self::REASON_ACCESSORY,
            ])
            ->values();
    }

    /**
     * Upsell offers for everything currently in the cart (items.product.decks must be loaded).
     *
     * @return Collection<int, array{anchor_item_id: string, product: Product, reason: string, unit_discount_cents: int}>
     */
    public function suggestionsForCart(Cart $cart): Collection
    {
        $inCart = $cart->items->pluck('product_id')->all();
        $suggestions = collect();

        foreach ($cart->items as $item) {
            foreach ($this->bundleCandidatesFor($item->product) as $candidate) {
                $product = $candidate['product'];

                if (in_array($product->id, $inCart, true) || $suggestions->has($product->id)) {
                    continue;
                }

                $suggestions->put($product->id, [
                    'anchor_item_id' => $item->id,
                    'product' => $product,
                    'reason' => $candidate['reason'],
                    'unit_discount_cents' => $this->unitDiscountCents($product),
                ]);
            }
        }

        return $suggestions->values()->take((int) config('provoke.upsell.max_suggestions'));
    }

    /**
     * Product-page recommendations.
     *
     * @return Collection<int, array{product: Product, reason: string, unit_discount_cents: int}>
     */
    public function recommendationsFor(Product $product): Collection
    {
        $limit = (int) config('provoke.upsell.max_suggestions');

        if ($product->product_type === ProductType::DigitalDeck) {
            return $this->bundleCandidatesFor($product)
                ->map(fn (array $c) => $c + ['unit_discount_cents' => $this->unitDiscountCents($c['product'])])
                ->take($limit);
        }

        $product->loadMissing('decks');
        if ($product->decks->isEmpty()) {
            return collect();
        }

        return Product::active()
            ->whereKeyNot($product->getKey())
            ->whereHas('decks', fn ($q) => $q->whereIn('decks.id', $product->decks->modelKeys()))
            ->with('decks:id,deck_code,segment')
            ->limit($limit)
            ->get()
            ->filter(fn (Product $p) => $p->isPurchasable())
            ->map(fn (Product $p) => ['product' => $p, 'reason' => self::REASON_RELATED, 'unit_discount_cents' => 0])
            ->values();
    }
}
