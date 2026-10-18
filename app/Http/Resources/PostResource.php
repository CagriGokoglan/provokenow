<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Post */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'url' => FrontendUrl::post($this->resource),
            'excerpt' => $this->excerpt,
            'featured_image' => $this->featuredImageUrl(),
            'is_pillar' => $this->is_pillar,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['name' => $this->category->name, 'slug' => $this->category->slug] : null),
            'author' => ['name' => $this->author_name, 'role' => $this->author_role],
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
