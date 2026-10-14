<?php

namespace App\Models;

use App\Enums\DeckSegment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'deck_code', 'title', 'segment', 'intensity_level', 'core_theme_keywords',
    'ai_system_role_prompt', 'pod_card_box_theme', 'digital_delivery_file_name', 'is_active',
])]
#[Hidden(['ai_system_role_prompt'])]
class Deck extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'segment' => DeckSegment::class,
            'intensity_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->orderBy('card_number');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_deck_claims')->withTimestamps();
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    /** Cards hidden behind the vault for users who have set a vault passcode. */
    public function isVaultProtected(): bool
    {
        return in_array($this->segment->value, config('provoke.vault.protected_segments', []), true);
    }

    /** @return list<string> */
    public function themeKeywords(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->core_theme_keywords))));
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
