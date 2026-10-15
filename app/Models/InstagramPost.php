<?php

namespace App\Models;

use App\Models\Concerns\FlushesFrontendCache;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A curated Instagram post: its link plus our own copy of the cover image. Instagram images
 * can't be hotlinked, and its embed script sets Meta cookies before consent, so we don't embed.
 */
#[Fillable(['post_url', 'image', 'caption', 'sort_order', 'is_active'])]
class InstagramPost extends Model
{
    use FlushesFrontendCache, HasUuids;

    public const HOMEPAGE_LIMIT = 3;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->latest();
    }

    public function imageUrl(): ?string
    {
        return MediaUrl::resolve($this->image);
    }
}
