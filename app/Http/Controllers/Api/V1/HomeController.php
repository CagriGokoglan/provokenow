<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeckResource;
use App\Http\Resources\ProductResource;
use App\Models\Deck;
use App\Models\HeroSlide;
use App\Models\InstagramPost;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Support\ApiResponse;
use App\Support\YouTube;
use Illuminate\Http\JsonResponse;

/**
 * Everything the homepage renders, in one request, in section order. Every section is
 * admin-managed; an empty section comes back empty and the web app hides it.
 */
class HomeController extends Controller
{
    public const GRID_LIMIT = 8;

    public const SHOWCASE_LIMIT = 6;

    public function __invoke(): JsonResponse
    {
        $products = fn () => Product::storefront()->with(['decks:id,deck_code', 'category:id,name,slug']);

        $slides = HeroSlide::live()
            ->with(['deck:id,deck_code,is_active', 'product:id,slug,is_active'])
            ->get()
            ->map(fn (HeroSlide $s) => [
                'id' => $s->id,
                'eyebrow' => $s->eyebrow,
                'title' => $s->title,
                'subtitle' => $s->subtitle,
                'image' => $s->imageUrl(),
                'image_mobile' => $s->mobileImageUrl(),
                'youtube_id' => YouTube::id($s->video_url),
                'video_start' => (int) $s->video_start,
                'video_end_trim' => (int) $s->video_end_trim,
                'href' => $s->href(),
                'cta_label' => $s->ctaLabel(),
                'kind' => $s->target_type,
            ])
            ->filter(fn (array $s) => $s['href'] !== null)
            ->values();

        $video = (array) SiteSetting::value('home_video', []);
        $club = (array) SiteSetting::value('club', []);
        $instagram = (array) SiteSetting::value('instagram', []);

        return ApiResponse::success([
            'hero_slides' => $slides,
            'popular_products' => ProductResource::collection($products()->where('is_featured', true)->orderBy('sort_order')->orderBy('name')->limit(self::GRID_LIMIT)->get()),
            'showcase_decks' => DeckResource::collection(Deck::active()->with(['categories' => fn ($q) => $q->active()])->orderBy('deck_code')->limit(self::SHOWCASE_LIMIT)->get()),
            'boxed_games' => ProductResource::collection($products()->where('is_boxed_game', true)->orderBy('sort_order')->orderBy('name')->limit(self::SHOWCASE_LIMIT)->get()),
            'new_arrivals' => ProductResource::collection($products()->where('is_new_arrival', true)->orderBy('sort_order')->latest()->limit(self::GRID_LIMIT)->get()),
            'video' => [
                'youtube_id' => YouTube::id($video['url'] ?? null),
                'opacity' => max(0, min(100, (int) ($video['opacity'] ?? 100))),
                'start' => max(0, (int) ($video['start'] ?? 0)),
                'end_trim' => max(0, (int) ($video['end_trim'] ?? 0)),
                'headline' => $video['headline'] ?? null,
            ],
            'club' => [
                'enabled' => (bool) ($club['enabled'] ?? true),
                'headline' => $club['headline'] ?? null,
                'body' => $club['body'] ?? null,
            ],
            'instagram' => [
                'handle' => $instagram['handle'] ?? null,
                'profile_url' => $instagram['profile_url'] ?? null,
                'posts' => InstagramPost::live()->limit(InstagramPost::HOMEPAGE_LIMIT)->get()->map(fn (InstagramPost $p) => [
                    'id' => $p->id,
                    'url' => $p->post_url,
                    'image' => $p->imageUrl(),
                    'caption' => $p->caption,
                ])->values(),
            ],
        ], 'Homepage.');
    }
}
