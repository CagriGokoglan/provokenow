<?php

namespace App\Services\Access;

use App\Models\Deck;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Opt-in privacy layer. Users who set a vault passcode must re-verify it (per API token,
 * for a limited time) before cards of vault-protected decks are returned.
 */
class VaultService
{
    public function hasPasscode(User $user): bool
    {
        return (bool) $user->profile?->hasVaultPasscode();
    }

    public function setPasscode(User $user, string $passcode): void
    {
        $profile = $user->profile()->firstOrCreate();
        $profile->digital_vault_passcode_hash = $passcode; // hashed by the model cast
        $profile->save();
        $user->setRelation('profile', $profile);

        $this->lock($user);
    }

    public function verify(User $user, string $passcode): bool
    {
        $hash = $user->profile?->digital_vault_passcode_hash;

        return $hash !== null && Hash::check($passcode, $hash);
    }

    public function unlock(User $user): CarbonImmutable
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(config('provoke.vault.unlock_ttl_minutes'));
        Cache::put($this->cacheKey($user), true, $expiresAt);

        return $expiresAt;
    }

    public function lock(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    public function isUnlocked(User $user): bool
    {
        return Cache::has($this->cacheKey($user));
    }

    /** Whether this user must unlock the vault before seeing the deck's cards. */
    public function blocksDeck(User $user, Deck $deck): bool
    {
        return $deck->isVaultProtected() && $this->hasPasscode($user) && ! $this->isUnlocked($user);
    }

    /** Scoped to the current API token so unlocking on one device doesn't unlock others. */
    private function cacheKey(User $user): string
    {
        $token = $user->currentAccessToken();
        $tokenId = $token instanceof PersonalAccessToken ? $token->getKey() : 'session';

        return "vault-unlocked:{$user->getKey()}:{$tokenId}";
    }
}
