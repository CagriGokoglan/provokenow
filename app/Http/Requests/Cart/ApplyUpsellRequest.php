<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class ApplyUpsellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Omit product_id to only evaluate the rules and list offers.
            'product_id' => ['sometimes', 'uuid'],
            'anchor_item_id' => ['sometimes', 'uuid'],
        ];
    }
}
