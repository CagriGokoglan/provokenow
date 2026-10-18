<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeckResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\ProductResource;
use App\Models\Post;
use App\Models\PostCategory;
use App\Services\Blog\ArticleRenderer;
use App\Services\Seo\Faqs;
use App\Services\Seo\SchemaGeneratorService;
use App\Services\Seo\SeoMetadataService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Journal: published posts only. */
class BlogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', 'string', 'max:80'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $posts = Post::published()
            ->with('category')
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->orderByDesc('is_pillar')
            ->orderByDesc('published_at')
            ->paginate($filters['per_page'] ?? 24);

        return ApiResponse::paginated($posts, PostResource::class, 'Posts retrieved.');
    }

    public function categories(): JsonResponse
    {
        return ApiResponse::success([
            'categories' => PostCategory::active()
                ->whereHas('posts', fn ($q) => $q->published())
                ->get(['name', 'slug', 'description']),
        ], 'Journal categories.');
    }

    public function show(string $slug, ArticleRenderer $renderer, Faqs $faqs, SeoMetadataService $seo, SchemaGeneratorService $schema): JsonResponse
    {
        $post = Post::published()
            ->where('slug', $slug)
            ->with([
                'category',
                'pillar' => fn ($q) => $q->published(),
                'clusterPosts' => fn ($q) => $q->published()->orderByDesc('published_at'),
                'decks' => fn ($q) => $q->active(),
                'products' => fn ($q) => $q->storefront()->with('decks:id,deck_code', 'category:id,name,slug'),
            ])
            ->firstOrFail();

        $article = $renderer->render($post->content);
        $postFaqs = $faqs->clean($post->faqs);

        // Siblings in the same cluster (the pillar lists its cluster; a cluster post links back).
        $cluster = $post->is_pillar
            ? $post->clusterPosts
            : ($post->pillar ? $post->pillar->clusterPosts()->published()->whereKeyNot($post->getKey())->orderByDesc('published_at')->get() : collect());

        return ApiResponse::success([
            'post' => [
                ...(new PostResource($post))->resolve(),
                'tldr' => $post->tldr,
                'sections' => $article['sections'],
                'toc' => $article['toc'],
                'reading_minutes' => $article['reading_minutes'],
                'author' => ['name' => $post->author_name, 'role' => $post->author_role, 'bio' => $post->author_bio],
                'updated_at' => $post->updated_at?->toIso8601String(),
            ],
            'faqs' => $postFaqs,
            'decks' => DeckResource::collection($post->decks),
            'products' => ProductResource::collection($post->products),
            'pillar' => $post->pillar ? new PostResource($post->pillar) : null,
            'cluster' => PostResource::collection($cluster),
            'seo' => $seo->forPost($post),
            'schema_json_ld' => $schema->forPost($post, $postFaqs),
        ], 'Post.');
    }
}
