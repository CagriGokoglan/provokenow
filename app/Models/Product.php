<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sku', 'name', 'slug', 'product_type', 'price_cents', 'sale_price_cents',
    'stock_quantity', 'is_active', 'description', 'metadata',
])]
class Product extends Model
{
    use HasUuids;

    public const UNLIMITED_STOCK = -1;

    protected $attributes = [
        'stock_quantity' => self::UNLIMITED_STOCK,
        'is_active' => true,
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
        ];
    }

    /** Decks a purchase of this product grants access to. */
    public function decks(): BelongsToMany
    {
        return $this->belongsToMany(Deck::class, 'product_deck_claims')->withTimestamps();
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

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
