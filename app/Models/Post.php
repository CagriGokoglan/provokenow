<?php

namespace App\Models;

use App\Models\Concerns\FlushesFrontendCache;
use App\Models\Concerns\OptimizesImages;
use App\Services\Media\ImageOptimizer;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'post_category_id', 'pillar_post_id', 'is_pillar', 'title', 'slug', 'excerpt', 'tldr', 'content',
    'featured_image', 'seo_title', 'seo_description', 'author_name', 'author_role', 'author_bio',
    'faqs', 'is_published', 'published_at',
])]
class Post extends Model
{
    use FlushesFrontendCache, HasUuids, OptimizesImages;

    protected function casts(): array
    {
        return ['is_pillar' => 'boolean', 'is_published' => 'boolean', 'published_at' => 'datetime', 'faqs' => 'array'];
    }

    public function imageFields(): array
    {
        return ['featured_image' => ImageOptimizer::PROFILE_WEB];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    public function pillar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pillar_post_id');
    }

    /** Supporting posts of a pillar (its topic cluster). */
    public function clusterPosts(): HasMany
    {
        return $this->hasMany(self::class, 'pillar_post_id');
    }

    /** Decks promoted in the post's CTA cards. */
    public function decks(): BelongsToMany
    {
        return $this->belongsToMany(Deck::class, 'post_deck')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /** Store products promoted in the post's CTA cards. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /** Live on the site: published, and the publish time has come. */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function featuredImageUrl(): ?string
    {
        return MediaUrl::resolve($this->featured_image);
    }
}
