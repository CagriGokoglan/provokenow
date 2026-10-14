<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cart_id', 'product_id', 'quantity', 'upsell_anchor_item_id'])]
class CartItem extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The digital-deck line this item was upsold against. */
    public function upsellAnchor(): BelongsTo
    {
        return $this->belongsTo(CartItem::class, 'upsell_anchor_item_id');
    }
}
