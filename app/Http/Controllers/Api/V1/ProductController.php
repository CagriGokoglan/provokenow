<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\UpsellOfferResource;
use App\Models\Product;
use App\Services\Commerce\UpsellEngine;
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
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $products = Product::active()
            ->with('decks:id,deck_code')
            ->when($filters['product_type'] ?? null, fn ($q, $type) => $q->where('product_type', $type))
            ->when($filters['deck_code'] ?? null, fn ($q, $code) => $q->whereHas('decks', fn ($d) => $d->where('deck_code', strtoupper($code))))
            ->orderBy('product_type')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 24);

        return ApiResponse::paginated($products, ProductResource::class, 'Products retrieved.');
    }

    public function show(string $slug, UpsellEngine $upsell): JsonResponse
    {
        $product = Product::active()->where('slug', $slug)->with('decks')->firstOrFail();

        return ApiResponse::success([
            'product' => new ProductResource($product),
            'recommendations' => UpsellOfferResource::collection($upsell->recommendationsFor($product)),
        ], 'Product retrieved.');
    }
}
