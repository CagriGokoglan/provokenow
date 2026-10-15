<?php

namespace App\Models;

use App\Support\FrontendUrl;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'eyebrow', 'title', 'subtitle', 'image', 'image_mobile', 'video_url', 'target_type', 'deck_id', 'product_id',
    'custom_url', 'cta_label', 'sort_order', 'is_active', 'starts_at', 'ends_at',
])]
class HeroSlide extends Model
{
    use HasUuids;

    public const TARGET_DECK = 'deck';

    public const TARGET_PRODUCT = 'product';

    public const TARGET_CUSTOM = 'custom';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Slides that are switched on and inside their schedule window. */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort_order');
    }

    /** Site-relative link for the CTA, or null when its target is gone or inactive. */
    public function href(): ?string
    {
        $path = fn (string $url) => parse_url($url, PHP_URL_PATH) ?: '/';

        return match ($this->target_type) {
            self::TARGET_DECK => $this->deck?->is_active ? $path(FrontendUrl::deck($this->deck)) : null,
            self::TARGET_PRODUCT => $this->product?->is_active ? $path(FrontendUrl::product($this->product)) : null,
            default => $this->custom_url ?: null,
        };
    }

    public function ctaLabel(): string
    {
        return $this->cta_label ?: match ($this->target_type) {
            self::TARGET_DECK => 'Explore Deck',
            self::TARGET_PRODUCT => 'Shop Now',
            default => 'Discover',
        };
    }

    public function imageUrl(): ?string
    {
        return MediaUrl::resolve($this->image);
    }

    public function mobileImageUrl(): ?string
    {
        return MediaUrl::resolve($this->image_mobile) ?? $this->imageUrl();
    }

    public function videoUrl(): ?string
    {
        return MediaUrl::resolve($this->video_url);
    }
}
