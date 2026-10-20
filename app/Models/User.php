<?php

namespace App\Models;

use App\Enums\EntitlementStatus;
use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
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
class User extends Authenticatable implements FilamentUser, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, MustVerifyEmail, Notifiable;

    /** Branded, queued Brevo email linking to the web app (not the API). */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /** @param  string  $token */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    protected $attributes = [
        'role' => UserRole::Customer->value,
        'is_adult_unlocked' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_adult_unlocked' => 'boolean',
            'adult_unlocked_at' => 'datetime',
            'age_confirmed_at' => 'datetime',
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

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class)->orderByDesc('is_default')->oldest();
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

    /** A member who confirmed their email (staff always count). Card content needs this. */
    public function isVerifiedMember(): bool
    {
        return $this->isStaff() || $this->hasVerifiedEmail();
    }

    /** Verified, and chose +18 "Passion mode": sees the Advanced and Taboo tiers. */
    public function hasAdultAccess(): bool
    {
        return $this->isStaff() || ($this->hasVerifiedEmail() && $this->is_adult_unlocked);
    }

    /** Turns +18 Passion mode on or off (kept with the time it was last switched on). */
    public function setAdultUnlocked(bool $on): void
    {
        $this->forceFill(['is_adult_unlocked' => $on, 'adult_unlocked_at' => $on ? now() : $this->adult_unlocked_at])->save();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    /** /admin is restricted to admin and superadmin roles. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isStaff();
    }
}
