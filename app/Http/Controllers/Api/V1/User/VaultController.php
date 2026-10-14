<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\SetVaultPasscodeRequest;
use App\Http\Requests\Vault\VerifyVaultPasscodeRequest;
use App\Services\Access\VaultService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class VaultController extends Controller
{
    public function __construct(private readonly VaultService $vault) {}

    public function setPasscode(SetVaultPasscodeRequest $request): JsonResponse
    {
        $user = $request->user();
        $hadPasscode = $this->vault->hasPasscode($user);

        $this->vault->setPasscode($user, $request->validated('passcode'));

        return ApiResponse::success(
            ['has_vault_passcode' => true, 'vault_unlocked' => false],
            $hadPasscode ? 'Vault passcode updated.' : 'Vault passcode set.',
        );
    }

    public function verify(VerifyVaultPasscodeRequest $request): JsonResponse
    {
        $user = $request->user()->load('profile');

        if (! $this->vault->hasPasscode($user)) {
            return ApiResponse::error('No vault passcode has been set.', 409);
        }

        if (! $this->vault->verify($user, $request->validated('passcode'))) {
            throw ValidationException::withMessages(['passcode' => 'The vault passcode is incorrect.']);
        }

        $expiresAt = $this->vault->unlock($user);

        return ApiResponse::success([
            'vault_unlocked' => true,
            'unlocked_until' => $expiresAt->toIso8601String(),
        ], 'Vault unlocked.');
    }
}
