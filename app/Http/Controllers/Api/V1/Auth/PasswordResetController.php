<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /** Same answer whether or not the address has an account (no account discovery). */
    public function forgot(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);

        Password::sendResetLink($data);

        return ApiResponse::success(null, 'If an account exists for that address, a reset link is on its way.');
    }

    public function reset(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)->letters()->mixedCase()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save(); // hashed by the model cast

            // Every existing session ends; following the emailed link also proves the address.
            $user->tokens()->delete();
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? ApiResponse::success(null, 'Your password has been reset. Sign in with your new password.')
            : ApiResponse::error('This reset link is invalid or has expired. Request a new one.', 422, ['token' => ['invalid']]);
    }
}
