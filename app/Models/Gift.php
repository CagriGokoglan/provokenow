<?php

namespace App\Models;

use App\Enums\GiftStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A paid order sent to someone else, claimed by them into their Vault. */
#[Fillable([
    'order_id', 'sender_id', 'recipient_email', 'recipient_id', 'sender_alias', 'gift_message',
    'deliver_at', 'claim_token_hash', 'status', 'sent_at', 'expires_at', 'claimed_at',
])]
class Gift extends Model
{
    use HasUuids;

    /** Unclaimed gifts expire this many days after the recipient is emailed. */
    public const CLAIM_DAYS = 90;

    protected function casts(): array
    {
        return [
            'status' => GiftStatus::class,
            'deliver_at' => 'datetime',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    /** A new secret for the claim link. Only its hash is stored. */
    public function issueClaimToken(): string
    {
        $token = Str::random(48);
        $this->claim_token_hash = self::hashToken($token);

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return $token === '' ? null : static::firstWhere('claim_token_hash', self::hashToken($token));
    }

    /** The decks this gift unlocks (empty for physical-only gifts). @return list<string> */
    public function deckIds(): array
    {
        return $this->order->items->flatMap(fn (OrderItem $item) => (array) ($item->metadata['deck_ids'] ?? []))->unique()->values()->all();
    }

    public function hasVaultItems(): bool
    {
        return $this->deckIds() !== [];
    }

    public function isClaimable(): bool
    {
        return $this->status === GiftStatus::Sent && ($this->expires_at === null || $this->expires_at->isFuture()) && $this->hasVaultItems();
    }

    /** How the sender is named to the recipient. */
    public function fromName(): string
    {
        return filled($this->sender_alias) ? $this->sender_alias : 'Someone special';
    }
}
