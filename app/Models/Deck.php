<?php

namespace App\Models;

use App\Enums\DeckSegment;
use App\Enums\ProductType;
use App\Models\Concerns\FlushesFrontendCache;
use App\Models\Concerns\OptimizesImages;
use App\Services\Catalog\DigitalEdition;
use App\Services\Media\ImageOptimizer;
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
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'deck_code', 'title', 'segment', 'intensity_level', 'core_theme_keywords',
    'digital_price_cents', 'digital_sku', 'is_digital_sale_active',
    'is_active',
    'meta_title', 'meta_description', 'canonical_url', 'og_image', 'faqs',
    'card_front_bg_url', 'card_back_bg_url',
])]
class Deck extends Model
{
    use FlushesFrontendCache, HasFactory, HasUuids, OptimizesImages;

    /** Uploaded images re-encoded in the background (see OptimizesImages). */
    public function imageFields(): array
    {
        return [
            'card_front_bg_url' => ImageOptimizer::PROFILE_WEB,
            'card_back_bg_url' => ImageOptimizer::PROFILE_WEB,
            'og_image' => ImageOptimizer::PROFILE_OG,
        ];
    }

    protected function casts(): array
    {
        return [
            'segment' => DeckSegment::class,
            'intensity_level' => 'integer',
            'is_active' => 'boolean',
            'faqs' => 'array',
            'digital_price_cents' => 'integer',
            'is_digital_sale_active' => 'boolean',
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

        // The digital edition (price, SKU, on sale) is edited here and kept in its product.
        // (wasRecentlyCreated stays true on the instance, so creation and later edits are separate hooks.)
        static::created(fn (Deck $deck) => app(DigitalEdition::class)->sync($deck));
        static::updated(function (Deck $deck) {
            if ($deck->wasChanged(['digital_price_cents', 'digital_sku', 'is_digital_sale_active', 'title', 'is_active'])) {
                app(DigitalEdition::class)->sync($deck);
            }
        });
    }

    /** The physical boxed game linked to this deck, if any. */
    public function physicalProduct(): HasOne
    {
        return $this->hasOne(Product::class, 'linked_deck_id')->where('product_type', ProductType::PodPhysicalDeck->value);
    }

    /** The product that sells this deck digitally (managed from the deck, hidden from the store). */
    public function digitalProduct(): HasOne
    {
        return $this->hasOne(Product::class, 'linked_deck_id')->where('product_type', ProductType::DigitalDeck->value);
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
