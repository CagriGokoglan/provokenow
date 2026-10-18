<?php

namespace App\Services\Commerce;

use App\Enums\EntitlementStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Models\Deck;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\UserEntitlement;

/**
 * A deck's two editions and what a customer owns of them.
 *
 * Editions are the active products linked to the deck (product_deck_claims): the digital deck
 * and the physical box. Buying the box unlocks the digital deck too (its claim grants access
 * at payment). A customer who paid for the digital deck gets that amount back as credit on their
 * first physical box of the same deck.
 */
class DeckEditions
{
    /** @return array{digital: ?Product, physical: ?Product} */
    public function editionsFor(Deck $deck): array
    {
        $products = Product::active()
            ->where(fn ($q) => $q->where('linked_deck_id', $deck->getKey())->orWhereHas('decks', fn ($d) => $d->whereKey($deck->getKey())))
            ->with('decks:id,deck_code', 'category:id,name,slug')
            ->get();

        // The deck's own linked products first (set in the admin), then anything else unlocking it.
        $linked = fn (Product $p) => $p->linked_deck_id === $deck->getKey() ? 0 : 1;

        return [
            'digital' => $products->where('product_type', ProductType::DigitalDeck)
                ->sortBy(fn (Product $p) => [$linked($p), $p->effectivePriceCents()])
                ->first(),
            'physical' => $products->filter(fn (Product $p) => $this->isPhysicalEdition($p))
                ->sortBy(fn (Product $p) => [$p->product_type === ProductType::PodPhysicalDeck ? $linked($p) : 2, $p->is_boxed_game ? 0 : 1, $p->effectivePriceCents()])
                ->first(),
        ];
    }

    /** A product that ships and unlocks one or more decks: a physical edition of those decks. */
    public function isPhysicalEdition(Product $product): bool
    {
        return $product->requiresShipping() && $product->decks->isNotEmpty();
    }

    /** @return array{owns_digital: bool, owns_physical: bool, digital_credit_cents: int} */
    public function ownership(?User $user, Deck $deck): array
    {
        if (! $user) {
            return ['owns_digital' => false, 'owns_physical' => false, 'digital_credit_cents' => 0];
        }

        $ownsPhysical = $this->ownsPhysical($user, $deck);

        return [
            'owns_digital' => $user->hasAccessToDeck($deck),
            'owns_physical' => $ownsPhysical,
            'digital_credit_cents' => $ownsPhysical ? 0 : $this->paidDigitalPriceCents($user, $deck->getKey()),
        ];
    }

    /** Has a paid (not refunded) order containing a shipped product that unlocks this deck. */
    public function ownsPhysical(User $user, Deck $deck): bool
    {
        return OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->getKey())->where('payment_status', PaymentStatus::Paid->value))
            ->where('metadata->requires_shipping', true)
            ->whereJsonContains('metadata->deck_ids', $deck->getKey())
            ->exists();
    }

    /**
     * Upgrade credit for buying this physical product: what the customer actually paid for the
     * digital edition of each deck it unlocks, for decks they don't own physically yet. Capped
     * at the product price. Access granted for free (admin, gifts) earns no credit.
     */
    public function creditForProduct(?User $user, Product $product): int
    {
        if (! $user || ! $this->isPhysicalEdition($product)) {
            return 0;
        }

        $credit = 0;
        foreach ($product->decks as $deck) {
            if (! $this->ownsPhysical($user, $deck)) {
                $credit += $this->paidDigitalPriceCents($user, $deck->getKey());
            }
        }

        return min($credit, $product->effectivePriceCents());
    }

    /** Price paid per copy on the digital (non-shipped) order line that granted access, else 0. */
    private function paidDigitalPriceCents(User $user, string $deckId): int
    {
        $orderId = UserEntitlement::query()
            ->where('user_id', $user->getKey())
            ->where('deck_id', $deckId)
            ->where('status', EntitlementStatus::Active->value)
            ->whereNotNull('order_id')
            ->value('order_id');

        if (! $orderId) {
            return 0;
        }

        $line = OrderItem::query()
            ->where('order_id', $orderId)
            ->where('metadata->requires_shipping', false)
            ->whereJsonContains('metadata->deck_ids', $deckId)
            ->first(['subtotal_cents', 'quantity']);

        return $line ? intdiv($line->subtotal_cents, max(1, $line->quantity)) : 0;
    }
}
