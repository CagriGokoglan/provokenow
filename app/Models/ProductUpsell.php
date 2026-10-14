<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'upsell_product_id', 'discount_percent', 'sort_order'])]
class ProductUpsell extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'discount_percent' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function upsellProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'upsell_product_id');
    }
}
