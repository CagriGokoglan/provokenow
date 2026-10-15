<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The emailed link opens the web app, which posts the signed path here. No sign-in needed:
 * the link may be opened on another device. Responses carry a `reason` the page can act on.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return ApiResponse::error('This verification link is not valid.', 403, ['reason' => ['invalid']]);
        }

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(['verified' => true, 'already' => true], 'Your email is already verified.');
        }

        // Signature checked after the identity, so an expired link for a real account can offer a resend.
        if (! $request->hasValidRelativeSignature()) {
            $expired = (int) $request->query('expires') > 0 && (int) $request->query('expires') < now()->getTimestamp();

            return ApiResponse::error(
                $expired ? 'This verification link has expired.' : 'This verification link is not valid.',
                403,
                ['reason' => [$expired ? 'expired' : 'invalid']],
            );
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return ApiResponse::success(['verified' => true, 'already' => false], 'Email verified.');
    }

    /**
     * Sends a fresh link: to the signed-in user, or for the account behind an expired link (by id).
     * Always answers the same way, so it can't be used to discover accounts.
     */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['sometimes', 'nullable', 'uuid']]);

        $user = $request->user('sanctum') ?? (isset($data['id']) ? User::find($data['id']) : null);

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return ApiResponse::success(null, 'If the address still needs verifying, a new link is on its way.');
    }
}
