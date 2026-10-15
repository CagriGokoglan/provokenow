<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\UpsellOfferResource;
use App\Models\Product;
use App\Services\Commerce\UpsellEngine;
use App\Services\Seo\SchemaGeneratorService;
use App\Services\Seo\SeoMetadataService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'product_type' => ['sometimes', Rule::enum(ProductType::class)],
            'deck_code' => ['sometimes', 'string', 'max:20'],
            'category' => ['sometimes', 'string', 'max:80'],
            // Store tab "Games": every playable deck product (digital, printed box, bundle).
            'kind' => ['sometimes', Rule::in(['games', 'lifestyle'])],
            'boxed' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'new_arrival' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $games = [ProductType::DigitalDeck->value, ProductType::PodPhysicalDeck->value, ProductType::Bundle->value];

        $products = Product::active()
            ->with(['decks:id,deck_code', 'category:id,name,slug'])
            ->when($filters['product_type'] ?? null, fn ($q, $type) => $q->where('product_type', $type))
            ->when($filters['deck_code'] ?? null, fn ($q, $code) => $q->whereHas('decks', fn ($d) => $d->where('deck_code', strtoupper($code))))
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)->where('is_active', true)))
            ->when(($filters['kind'] ?? null) === 'games', fn ($q) => $q->whereIn('product_type', $games))
            ->when(($filters['kind'] ?? null) === 'lifestyle', fn ($q) => $q->whereNotIn('product_type', $games))
            ->when($request->boolean('boxed'), fn ($q) => $q->where('is_boxed_game', true))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->boolean('new_arrival'), fn ($q) => $q->where('is_new_arrival', true))
            ->orderBy('sort_order')
            ->when($request->boolean('new_arrival'), fn ($q) => $q->latest(), fn ($q) => $q->orderBy('name'))
            ->paginate($filters['per_page'] ?? 24);

        return ApiResponse::paginated($products, ProductResource::class, 'Products retrieved.');
    }

    public function show(string $slug, UpsellEngine $upsell, SeoMetadataService $seo, SchemaGeneratorService $schema): JsonResponse
    {
        $product = Product::active()->where('slug', $slug)->with(['decks', 'category'])->firstOrFail();

        return ApiResponse::success([
            'product' => new ProductResource($product),
            'recommendations' => UpsellOfferResource::collection($upsell->recommendationsFor($product)),
            'seo' => $seo->forProduct($product),
            'schema_json_ld' => $schema->forProduct($product),
        ], 'Product retrieved.');
    }
}
