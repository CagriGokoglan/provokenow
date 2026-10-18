<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'product_type' => $this->product_type->value,
            'price_cents' => $this->price_cents,
            'sale_price_cents' => $this->sale_price_cents,
            'effective_price_cents' => $this->effectivePriceCents(),
            'on_sale' => $this->sale_price_cents !== null,
            'currency' => config('provoke.commerce.currency'),
            'in_stock' => $this->hasStockFor(1),
            'requires_shipping' => $this->requiresShipping(),
            'images' => $this->images(),
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['name' => $this->category->name, 'slug' => $this->category->slug] : null),
            'is_featured' => $this->is_featured,
            'is_new_arrival' => $this->is_new_arrival,
            'is_boxed_game' => $this->is_boxed_game,
            'deck_claim_ids' => $this->whenLoaded('decks', fn () => $this->decks->modelKeys()),
            'deck_claims' => $this->whenLoaded('decks', fn () => $this->decks->map(fn ($deck) => [
                'id' => $deck->id,
                'deck_code' => $deck->deck_code,
                'slug' => $deck->slug,
            ])->values()),
        ];
    }
}
