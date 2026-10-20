<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Console\Command;

/** Lost phone and recovery codes: turns 2FA off for one account (run on the server only). */
class ResetTwoFactor extends Command
{
    protected $signature = 'admin:reset-2fa {email}';

    protected $description = 'Turn off two-factor authentication for an account that lost its device and recovery codes';

    public function handle(TwoFactor $twoFactor): int
    {
        $user = User::firstWhere('email', mb_strtolower((string) $this->argument('email')));
        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        $twoFactor->disable($user);
        $user->tokens()->delete();
        $this->info("Two-factor authentication is off for {$user->email}. They will set it up again at their next admin sign-in.");

        return self::SUCCESS;
    }
}
