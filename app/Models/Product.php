<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\FlushesFrontendCache;
use App\Models\Concerns\OptimizesImages;
use App\Services\Media\ImageOptimizer;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sku', 'name', 'slug', 'product_type', 'price_cents', 'sale_price_cents',
    'stock_quantity', 'is_active', 'description', 'metadata',
    'meta_title', 'meta_description', 'canonical_url', 'og_image',
    'product_category_id', 'is_featured', 'is_new_arrival', 'is_boxed_game', 'sort_order',
])]
class Product extends Model
{
    use FlushesFrontendCache, HasFactory, HasUuids, OptimizesImages;

    /** Uploaded images re-encoded in the background (see OptimizesImages). */
    public function imageFields(): array
    {
        return ['metadata.images' => ImageOptimizer::PROFILE_WEB, 'og_image' => ImageOptimizer::PROFILE_OG];
    }

    public const UNLIMITED_STOCK = -1;

    protected $attributes = [
        'stock_quantity' => self::UNLIMITED_STOCK,
        'is_active' => true,
        'is_featured' => false,
        'is_new_arrival' => false,
        'is_boxed_game' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'price_cents' => 'integer',
            'sale_price_cents' => 'integer',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_boxed_game' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /** Decks a purchase of this product grants access to. */
    public function decks(): BelongsToMany
    {
        return $this->belongsToMany(Deck::class, 'product_deck_claims')->withTimestamps();
    }

    /** Admin-curated upsell offers shown when this product is in the cart. */
    public function upsellRules(): HasMany
    {
        return $this->hasMany(ProductUpsell::class)->orderBy('sort_order');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function effectivePriceCents(): int
    {
        return $this->sale_price_cents ?? $this->price_cents;
    }

    public function hasUnlimitedStock(): bool
    {
        return $this->stock_quantity === self::UNLIMITED_STOCK;
    }

    public function hasStockFor(int $quantity): bool
    {
        return $this->hasUnlimitedStock() || $this->stock_quantity >= $quantity;
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->hasStockFor(1);
    }

    public function requiresShipping(): bool
    {
        return (bool) ($this->metadata['requires_shipping'] ?? $this->product_type->shipsByDefault());
    }

    /** @return list<string> */
    public function images(): array
    {
        return array_values(array_filter(array_map(
            fn ($image) => MediaUrl::resolve(is_string($image) ? $image : null),
            (array) ($this->metadata['images'] ?? []),
        )));
    }

    public function ogImageUrl(): ?string
    {
        return MediaUrl::resolve($this->og_image);
    }

    /**
     * Accessory targeting for the upsell engine, stored in metadata.upsell:
     *   { "segments": ["Medium_Spice"], "deck_codes": ["DECK-007"], "all_digital_decks": false }
     */
    public function upsellTargetsDeck(Deck $deck): bool
    {
        $rules = (array) ($this->metadata['upsell'] ?? []);

        return ($rules['all_digital_decks'] ?? false) === true
            || in_array($deck->segment->value, (array) ($rules['segments'] ?? []), true)
            || in_array($deck->deck_code, (array) ($rules['deck_codes'] ?? []), true);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
