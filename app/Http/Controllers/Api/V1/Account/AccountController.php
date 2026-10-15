<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

/** Account → Profile, Purchases and password. */
class AccountController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }

        $profile = collect($data)->only(['marketing_opt_in'])->all();
        if ($profile !== []) {
            $user->profile()->updateOrCreate([], $profile);
        }

        return ApiResponse::success(['user' => new UserResource($user->fresh('profile'))], 'Profile updated.');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);

        // Sign out every other device; keep the one that made the change.
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->delete();

        return ApiResponse::success(null, 'Password updated. Other devices were signed out.');
    }

    /** Purchases: the customer's orders, newest first (pending checkouts excluded). */
    public function orders(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->where('payment_status', '!=', 'pending')
            ->with(['items', 'entitlements.deck'])
            ->latest()
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return ApiResponse::paginated($orders, OrderResource::class, 'Orders retrieved.');
    }
}
