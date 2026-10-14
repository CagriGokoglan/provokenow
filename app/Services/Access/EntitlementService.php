<?php

namespace App\Services\Access;

use App\Enums\EntitlementStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Support\Collection;

class EntitlementService
{
    /** @return list<string> deck IDs the user can currently open */
    public function ownedDeckIds(User $user): array
    {
        return $user->entitlements()->valid()->pluck('deck_id')->all();
    }

    /** True when the product only unlocks decks the user already has. */
    public function userOwnsAllDecksOf(User $user, Product $product): bool
    {
        $deckIds = $product->decks->modelKeys();

        return $deckIds !== [] && array_diff($deckIds, $this->ownedDeckIds($user)) === [];
    }

    /**
     * Grant lifetime access to the given decks. Idempotent: an existing active entitlement
     * is reused (and made lifetime if it had an expiry) rather than duplicated.
     * Must run inside a transaction.
     *
     * @param  list<string>  $deckIds
     * @return Collection<int, UserEntitlement>
     */
    public function grant(User|string $user, array $deckIds, ?Order $order = null): Collection
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return collect(array_unique($deckIds))->map(function (string $deckId) use ($userId, $order) {
            $existing = UserEntitlement::query()
                ->where('user_id', $userId)
                ->where('deck_id', $deckId)
                ->where('status', EntitlementStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->expires_at !== null) {
                    $existing->update(['expires_at' => null]);
                }

                return $existing;
            }

            return UserEntitlement::create([
                'user_id' => $userId,
                'deck_id' => $deckId,
                'order_id' => $order?->getKey(),
                'granted_at' => now(),
                'status' => EntitlementStatus::Active,
            ]);
        })->values();
    }
}
