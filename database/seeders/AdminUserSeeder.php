<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Creates (or promotes) the default superadmin.
     *
     * Credentials come from config/provoke.php (ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD). If no password is
     * configured, a random one is generated and printed once — it is never hard-coded.
     */
    public function run(): void
    {
        $config = config('provoke.admin');
        $email = mb_strtolower((string) $config['email']);
        $user = User::firstWhere('email', $email);

        if ($user) {
            if ($user->role !== UserRole::SuperAdmin) {
                $user->role = UserRole::SuperAdmin;
                $user->save();
            }
            $this->command?->info("Superadmin already exists: {$email}");

            return;
        }

        $generated = blank($config['password']);
        $password = $generated ? Str::password(24) : $config['password'];

        $user = new User([
            'name' => $config['name'],
            'email' => $email,
            'password' => $password,
        ]);
        $user->role = UserRole::SuperAdmin;
        $user->email_verified_at = now();
        $user->save();

        $user->profile()->create();

        $this->command?->info("Superadmin created: {$email}");
        if ($generated) {
            $this->command?->warn("Generated password (shown once, store it securely): {$password}");
        }
    }
}
