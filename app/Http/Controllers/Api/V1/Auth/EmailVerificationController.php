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
 *
 * The page sends the member's +18 "Passion mode" choice with it (`adult_unlocked`); a valid
 * signed link proves the inbox is theirs, so the choice is saved even if not signed in here.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return ApiResponse::error('This verification link is not valid.', 403, ['reason' => ['invalid']]);
        }

        $choice = $request->validate(['adult_unlocked' => ['sometimes', 'boolean']]);
        $signed = $request->hasValidRelativeSignature();

        if ($user->hasVerifiedEmail()) {
            // Re-opening a still-valid link may change the choice; an old or forged one may not.
            if ($signed && array_key_exists('adult_unlocked', $choice)) {
                $user->setAdultUnlocked($choice['adult_unlocked']);
            }

            return ApiResponse::success(['verified' => true, 'already' => true, 'adult_unlocked' => $user->hasAdultAccess()], 'Your email is already verified.');
        }

        // Signature checked after the identity, so an expired link for a real account can offer a resend.
        if (! $signed) {
            $expired = (int) $request->query('expires') > 0 && (int) $request->query('expires') < now()->getTimestamp();

            return ApiResponse::error(
                $expired ? 'This verification link has expired.' : 'This verification link is not valid.',
                403,
                ['reason' => [$expired ? 'expired' : 'invalid']],
            );
        }

        $user->markEmailAsVerified();
        event(new Verified($user));
        if (array_key_exists('adult_unlocked', $choice)) {
            $user->setAdultUnlocked($choice['adult_unlocked']);
        }

        return ApiResponse::success(['verified' => true, 'already' => false, 'adult_unlocked' => $user->hasAdultAccess()], 'Email verified.');
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
