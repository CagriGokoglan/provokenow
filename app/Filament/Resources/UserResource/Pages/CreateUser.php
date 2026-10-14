<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** `role` is not mass-assignable; set it explicitly, and only staff roles for superadmins. */
    protected function handleRecordCreation(array $data): Model
    {
        $role = UserRole::tryFrom((string) ($data['role'] ?? '')) ?? UserRole::Customer;
        if ($role->isStaff() && ! auth()->user()->isSuperAdmin()) {
            $role = UserRole::Customer;
        }

        $user = new User(collect($data)->only(['name', 'email', 'password'])->all());
        $user->role = $role;
        $user->save();

        return $user;
    }
}
