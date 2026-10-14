<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry from UpsellEngine (suggestionsForCart / recommendationsFor).
 *
 * @property array{product: Product, reason: string, discount_percent: int, unit_discount_cents: int, anchor_item_id?: string} $resource
 */
class UpsellOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->resource['product'];
        $discount = $this->resource['unit_discount_cents'];

        return [
            'reason' => $this->resource['reason'],
            'anchor_item_id' => $this->resource['anchor_item_id'] ?? null,
            'bundle_discount_eligible' => $discount > 0,
            'discount_percent' => $discount > 0 ? $this->resource['discount_percent'] : 0,
            'applied_discount_cents' => $discount,
            'price_after_discount_cents' => $product->effectivePriceCents() - $discount,
            'product' => new ProductResource($product),
        ];
    }
}
