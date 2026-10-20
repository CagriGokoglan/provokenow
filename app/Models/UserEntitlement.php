<?php

namespace App\Models;

use App\Enums\EntitlementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Extends Pivot so it can back User::accessibleDecks(), but is used as a full model
 * (own UUID primary key, timestamps) everywhere else.
 */
#[Fillable(['user_id', 'deck_id', 'order_id', 'acquired_via', 'gift_id', 'granted_at', 'expires_at', 'status'])]
class UserEntitlement extends Pivot
{
    use HasUuids;

    protected $table = 'user_entitlements';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => EntitlementStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'status' => EntitlementStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    /** Set when the deck arrived as a gift (acquired_via = gift). */
    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isValid(): bool
    {
        return $this->status === EntitlementStatus::Active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Active and not expired. */
    #[Scope]
    protected function valid(Builder $query): void
    {
        $query->where('status', EntitlementStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function revoke(): void
    {
        $this->update(['status' => EntitlementStatus::Revoked]);
    }
}
