<?php

namespace App\Models;

use App\Enums\DeckSegment;
use App\Models\Concerns\FlushesFrontendCache;
use App\Support\MediaUrl;
use App\Support\SvgSanitizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'deck_code', 'title', 'segment', 'intensity_level', 'core_theme_keywords',
    'is_active',
    'meta_title', 'meta_description', 'canonical_url', 'og_image', 'seo_faq',
    'card_front_bg_url', 'card_back_bg_url',
])]
class Deck extends Model
{
    use FlushesFrontendCache, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'segment' => DeckSegment::class,
            'intensity_level' => 'integer',
            'is_active' => 'boolean',
            'seo_faq' => 'array',
        ];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->orderBy('card_number');
    }

    /** Fantasy / content tags (admin-managed). */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(DeckCategory::class, 'deck_category_deck');
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

    protected static function booted(): void
    {
        static::saving(function (Deck $deck) {
            foreach (['card_front_bg_url', 'card_back_bg_url'] as $field) {
                if ($deck->isDirty($field)) {
                    SvgSanitizer::sanitizeStored($deck->{$field}, $field);
                }
            }
        });
    }

    public function ogImageUrl(): ?string
    {
        return MediaUrl::resolve($this->og_image);
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
