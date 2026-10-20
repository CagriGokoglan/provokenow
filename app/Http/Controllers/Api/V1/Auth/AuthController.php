<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\GiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Gift;
use App\Models\User;
use App\Services\Gifts\GiftService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            // Role is never taken from input: self-registration is always a customer.
            $user = User::create($request->only('name', 'email', 'password'));

            $user->profile()->create([
                'marketing_opt_in' => $request->boolean('marketing_opt_in'),
            ]);

            return $user;
        });

        // From a gift invitation: link the gift. The link reached this inbox, so the same address
        // needs no separate verification email.
        $gift = filled($request->input('claim_token')) ? Gift::findByToken((string) $request->input('claim_token')) : null;
        if ($gift?->isClaimable() && $gift->recipient_id === null) {
            app(GiftService::class)->attach($gift, $user);
            if ($gift->recipient_email === $user->email) {
                $user->markEmailAsVerified();
            }
        }

        // Queued (after commit): the response doesn't wait for Brevo.
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return ApiResponse::success(
            $this->tokenPayload($user, $request->input('device_name')),
            'Registration successful.',
            201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return ApiResponse::error('The provided credentials are incorrect.', 401);
        }

        return ApiResponse::success(
            $this->tokenPayload($user, $request->input('device_name')),
            'Login successful.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            [
                'user' => new UserResource($request->user()->load('profile')),
                // Gifts waiting for this account's 18+ confirmation (the claim pop-up).
                'unclaimed_gifts' => Gift::where('recipient_id', $request->user()->getKey())->where('status', GiftStatus::Sent)->whereNotNull('claim_token_hash')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            ],
            'Authenticated user.',
        );
    }

    /** @return array{user: UserResource, token: string, token_type: string} */
    private function tokenPayload(User $user, ?string $deviceName): array
    {
        $token = $user->createToken($deviceName ?: 'api')->plainTextToken;

        return [
            'user' => new UserResource($user->load('profile')),
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }
}
