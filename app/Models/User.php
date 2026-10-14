<?php

namespace App\Models;

use App\Enums\EntitlementStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// `role` is intentionally not mass-assignable; set it explicitly (see AdminUserSeeder).
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $attributes = [
        'role' => UserRole::Customer->value,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    /** Decks the user currently has a valid (active, unexpired) entitlement to. */
    public function accessibleDecks(): BelongsToMany
    {
        return $this->belongsToMany(Deck::class, 'user_entitlements')
            ->using(UserEntitlement::class)
            ->withPivot(['id', 'order_id', 'granted_at', 'expires_at', 'status'])
            ->wherePivot('status', EntitlementStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('user_entitlements.expires_at')
                ->orWhere('user_entitlements.expires_at', '>', now()));
    }

    public function hasAccessToDeck(Deck|string $deck): bool
    {
        $deckId = $deck instanceof Deck ? $deck->getKey() : $deck;

        return $this->entitlements()->valid()->where('deck_id', $deckId)->exists();
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }
}
