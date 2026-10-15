<?php

namespace App\Services\Access;

use App\Enums\EntitlementStatus;
use App\Mail\DigitalAccessGrantedMailable;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserEntitlement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class EntitlementService
{
    /** @return list<string> deck IDs the user can currently open */
    public function ownedDeckIds(User $user): array
    {
        return $user->entitlements()->valid()->pluck('deck_id')->values()->all();
    }

    /** True when the product only unlocks decks the user already has. */
    public function userOwnsAllDecksOf(User $user, Product $product): bool
    {
        $deckIds = $product->decks->modelKeys();

        return $deckIds !== [] && array_diff($deckIds, $this->ownedDeckIds($user)) === [];
    }

    /**
     * Grant access to the given decks (lifetime unless $expiresAt is given). Idempotent: an
     * existing active entitlement is reused rather than duplicated, and existing access is
     * only ever extended, never shortened. Must run inside a transaction.
     *
     * @param  list<string>  $deckIds
     * @return Collection<int, UserEntitlement>
     */
    public function grant(User|string $user, array $deckIds, ?Order $order = null, ?CarbonInterface $expiresAt = null): Collection
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        $granted = collect(array_unique($deckIds))->map(function (string $deckId) use ($userId, $order, $expiresAt) {
            $existing = UserEntitlement::query()
                ->where('user_id', $userId)
                ->where('deck_id', $deckId)
                ->where('status', EntitlementStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $extended = ($existing->expires_at === null || $expiresAt === null)
                    ? null
                    : max($existing->expires_at, $expiresAt);

                if ($existing->expires_at != $extended) {
                    $existing->update(['expires_at' => $extended]);
                }

                return $existing;
            }

            return UserEntitlement::create([
                'user_id' => $userId,
                'deck_id' => $deckId,
                'order_id' => $order?->getKey(),
                'granted_at' => now(),
                'expires_at' => $expiresAt,
                'status' => EntitlementStatus::Active,
            ]);
        })->values();

        // Purchases are announced by the order receipt; anything else (admin grants, gifts) gets its own email.
        $new = $granted->filter(fn (UserEntitlement $e) => $e->wasRecentlyCreated)->pluck('deck_id')->all();
        if ($order === null && $new !== [] && ($recipient = $user instanceof User ? $user : User::find($userId))) {
            Mail::to($recipient)->queue(new DigitalAccessGrantedMailable($recipient, $new));
        }

        return $granted;
    }
}
