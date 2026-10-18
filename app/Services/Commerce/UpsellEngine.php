<?php

namespace App\Services\Commerce;

use App\Enums\ProductType;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductUpsell;
use Illuminate\Support\Collection;

/**
 * Upsell rules, in priority order:
 *  1. Curated (admin)  → product_upsells row for the anchor; its own discount_percent
 *  2. Digital deck     → POD physical box claiming the same deck        (default discount)
 *  3. Digital deck     → accessory whose metadata.upsell targets it     (default discount)
 *  4. Product page only → other products sharing a deck claim           (no discount)
 *
 * discountPercentFor() is the single source of truth: CartPricing re-checks it on every
 * pricing pass, so a discount disappears as soon as its anchor leaves the cart.
 */
class UpsellEngine
{
    public const REASON_CURATED = 'curated_upsell';

    public const REASON_POD_BOX = 'matching_pod_box';

    public const REASON_ACCESSORY = 'paired_accessory';

    public const REASON_RELATED = 'related_product';

    public function defaultDiscountPercent(): int
    {
        return (int) config('provoke.upsell.bundle_discount_percent');
    }

    public function unitDiscountCents(Product $product, int $percent): int
    {
        return intdiv($product->effectivePriceCents() * $percent, 100);
    }

    /** Bundle discount for adding $candidate against $anchor, or null when no rule links them. */
    public function discountPercentFor(Product $anchor, Product $candidate): ?int
    {
        if ($anchor->is($candidate)) {
            return null;
        }

        $anchor->loadMissing('upsellRules', 'decks');
        $candidate->loadMissing('decks');

        $curated = $anchor->upsellRules->firstWhere('upsell_product_id', $candidate->getKey());
        if ($curated) {
            return $curated->discount_percent;
        }

        return $this->implicitReason($anchor, $candidate) ? $this->defaultDiscountPercent() : null;
    }

    /**
     * Discountable add-ons for one anchor product.
     *
     * @return Collection<int, array{product: Product, reason: string, discount_percent: int}>
     */
    public function bundleCandidatesFor(Product $anchor): Collection
    {
        $anchor->loadMissing(['decks', 'upsellRules.upsellProduct.decks']);

        $curated = $anchor->upsellRules
            ->filter(fn (ProductUpsell $rule) => $rule->upsellProduct?->isPurchasable())
            ->map(fn (ProductUpsell $rule) => [
                'product' => $rule->upsellProduct,
                'reason' => self::REASON_CURATED,
                'discount_percent' => $rule->discount_percent,
            ]);

        $implicit = $this->implicitCandidates($anchor)
            ->reject(fn (Product $p) => $curated->contains(fn ($c) => $c['product']->is($p)))
            ->map(fn (Product $p) => [
                'product' => $p,
                'reason' => $this->implicitReason($anchor, $p),
                'discount_percent' => $this->defaultDiscountPercent(),
            ]);

        return $curated->concat($implicit)->values();
    }

    /**
     * Offers for everything currently in the cart.
     *
     * @return Collection<int, array{anchor_item_id: string, product: Product, reason: string, discount_percent: int, unit_discount_cents: int}>
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
                    ...$candidate,
                    'unit_discount_cents' => $this->unitDiscountCents($product, $candidate['discount_percent']),
                ]);
            }
        }

        return $suggestions->values()->take((int) config('provoke.upsell.max_suggestions'));
    }

    /**
     * Product-page recommendations: bundle offers first, then related products.
     *
     * @return Collection<int, array{product: Product, reason: string, discount_percent: int, unit_discount_cents: int}>
     */
    public function recommendationsFor(Product $product): Collection
    {
        $limit = (int) config('provoke.upsell.max_suggestions');

        $offers = $this->bundleCandidatesFor($product)
            ->map(fn (array $c) => $c + ['unit_discount_cents' => $this->unitDiscountCents($c['product'], $c['discount_percent'])]);

        if ($offers->count() >= $limit || $product->decks->isEmpty()) {
            return $offers->take($limit)->values();
        }

        $related = Product::active()
            ->whereKeyNot([$product->getKey(), ...$offers->map(fn ($o) => $o['product']->getKey())->all()])
            ->whereHas('decks', fn ($q) => $q->whereIn('decks.id', $product->decks->modelKeys()))
            ->with('decks:id,deck_code,slug,segment')
            ->limit($limit)
            ->get()
            ->filter(fn (Product $p) => $p->isPurchasable())
            ->map(fn (Product $p) => ['product' => $p, 'reason' => self::REASON_RELATED, 'discount_percent' => 0, 'unit_discount_cents' => 0]);

        return $offers->concat($related)->take($limit)->values();
    }

    /** @return Collection<int, Product> */
    private function implicitCandidates(Product $anchor): Collection
    {
        if ($anchor->product_type !== ProductType::DigitalDeck || $anchor->decks->isEmpty()) {
            return collect();
        }

        $pods = Product::active()
            ->where('product_type', ProductType::PodPhysicalDeck->value)
            ->whereHas('decks', fn ($q) => $q->whereIn('decks.id', $anchor->decks->modelKeys()))
            ->with('decks:id,deck_code,slug,segment')
            ->orderBy('price_cents')
            ->get();

        $accessories = Product::active()
            ->where('product_type', ProductType::PhysicalAccessory->value)
            ->whereNotNull('metadata->upsell')
            ->with('decks:id,deck_code,slug,segment')
            ->orderBy('price_cents')
            ->get();

        return $pods->concat($accessories)
            ->filter(fn (Product $p) => $p->isPurchasable() && $this->implicitReason($anchor, $p) !== null)
            ->values();
    }

    private function implicitReason(Product $anchor, Product $candidate): ?string
    {
        if ($anchor->product_type !== ProductType::DigitalDeck) {
            return null;
        }

        return match ($candidate->product_type) {
            ProductType::PodPhysicalDeck => array_intersect($candidate->decks->modelKeys(), $anchor->decks->modelKeys()) !== []
                ? self::REASON_POD_BOX : null,
            ProductType::PhysicalAccessory => $anchor->decks->contains(fn ($deck) => $candidate->upsellTargetsDeck($deck))
                ? self::REASON_ACCESSORY : null,
            default => null,
        };
    }
}
