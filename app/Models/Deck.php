<?php

namespace App\Models;

use App\Enums\DeckSegment;
use App\Enums\ProductType;
use App\Models\Concerns\FlushesFrontendCache;
use App\Models\Concerns\OptimizesImages;
use App\Services\Catalog\DigitalEdition;
use App\Services\Media\ImageOptimizer;
use App\Support\LineBreaks;
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
use Illuminate\Support\Str;

#[Fillable([
    'deck_code', 'slug', 'title', 'segment', 'intensity_level', 'core_theme_keywords', 'whats_inside', 'how_to_play', 'who_its_for',
    'digital_price_cents', 'digital_sale_price_cents', 'digital_sku', 'is_digital_sale_active',
    'is_active',
    'meta_title', 'meta_description', 'canonical_url', 'og_image', 'faqs',
    'card_front_bg_url', 'card_back_bg_url',
])]
class Deck extends Model
{
    use FlushesFrontendCache, HasFactory, HasUuids, OptimizesImages;

    /** Intensity is rated 1 (lowest) to 5 (highest), numbers only. */
    public const MAX_INTENSITY = 5;

    /** Free text written in the admin or imported from JSON (line breaks matter in these). */
    public const TEXT_FIELDS = ['whats_inside', 'how_to_play', 'who_its_for', 'core_theme_keywords', 'meta_description'];

    public const INTENSITY_OPTIONS = [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'];

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
            'previous_slugs' => 'array',
            'digital_price_cents' => 'integer',
            'digital_sale_price_cents' => 'integer',
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
            // URL from the title on first save; a changed slug keeps the old one as a redirect.
            if (blank($deck->slug)) {
                $deck->slug = static::uniqueSlug($deck->title ?: $deck->deck_code, $deck->getKey());
            } elseif ($deck->isDirty('slug') && filled($old = $deck->getOriginal('slug'))) {
                $deck->previous_slugs = array_values(array_unique([...(array) $deck->previous_slugs, $old]));
            }

            foreach (['card_front_bg_url', 'card_back_bg_url'] as $field) {
                if ($deck->isDirty($field)) {
                    SvgSanitizer::sanitizeStored($deck->{$field}, $field);
                }
            }

            // Literal "\n" from pasted / double-encoded JSON becomes a real line break.
            foreach (self::TEXT_FIELDS as $field) {
                if ($deck->isDirty($field)) {
                    $deck->{$field} = LineBreaks::fix($deck->{$field});
                }
            }
            if ($deck->isDirty('faqs')) {
                $deck->faqs = LineBreaks::fixAll($deck->faqs);
            }
        });

        // The digital edition (price, SKU, on sale) is edited here and kept in its product.
        // (wasRecentlyCreated stays true on the instance, so creation and later edits are separate hooks.)
        static::created(fn (Deck $deck) => app(DigitalEdition::class)->sync($deck));
        static::updated(function (Deck $deck) {
            if ($deck->wasChanged(['digital_price_cents', 'digital_sale_price_cents', 'digital_sku', 'is_digital_sale_active', 'title', 'is_active'])) {
                app(DigitalEdition::class)->sync($deck);
            }
        });
    }

    /** Slugs the deck can never have: the /decks/{level} pages. */
    public static function reservedSlugs(): array
    {
        return array_map(fn (DeckSegment $s) => $s->slug(), DeckSegment::cases());
    }

    public static function uniqueSlug(string $title, ?string $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'deck';
        if (in_array($base, static::reservedSlugs(), true)) {
            $base .= '-deck';
        }
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Finds an active deck by its slug, an earlier slug or its deck code (old links). */
    public static function findForUrl(string $param): ?self
    {
        $param = Str::lower(trim($param));

        return static::query()->active()
            ->where(fn ($q) => $q->where('slug', $param)
                ->orWhere('deck_code', Str::upper($param))
                ->orWhereJsonContains('previous_slugs', $param))
            ->first();
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
