<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'string', 'email:rfc', 'max:255'],
            'payment_gateway' => ['sometimes', 'string', 'in:stripe,paypal,test'],
            'shipping_address' => ['sometimes', 'array'],
            'shipping_address.name' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required_with:shipping_address', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['required_with:shipping_address', 'string', 'max:20'],
            'shipping_address.country' => ['required_with:shipping_address', 'string', 'size:2'],
            'shipping_address.phone' => ['nullable', 'string', 'max:40'],
            'billing_address' => ['sometimes', 'array'],
            'billing_address.name' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.line1' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.line2' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:120'],
            'billing_address.state' => ['nullable', 'string', 'max:120'],
            'billing_address.postal_code' => ['required_with:billing_address', 'string', 'max:20'],
            'billing_address.country' => ['required_with:billing_address', 'string', 'size:2'],
        ];
    }
}
