<?php

namespace App\Http\Requests\Vault;

use Illuminate\Foundation\Http\FormRequest;

class SetVaultPasscodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'passcode' => ['required', 'string', 'regex:/^\d{4,8}$/', 'confirmed'],
            // Re-authenticate with the account password before changing the vault passcode.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }

    public function messages(): array
    {
        return ['passcode.regex' => 'The passcode must be 4 to 8 digits.'];
    }
}
