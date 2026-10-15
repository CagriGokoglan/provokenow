<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClubSignup;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Provoke Club waitlist. Always answers the same way, so it can't reveal who is subscribed. */
class ClubController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'consent' => ['accepted'],
        ]);

        ClubSignup::firstOrCreate(
            ['email' => mb_strtolower(trim($data['email']))],
            ['user_id' => $request->user('sanctum')?->getKey(), 'source' => 'homepage', 'consented_at' => now()],
        );

        return ApiResponse::success(null, "You're on the list.", 201);
    }
}
