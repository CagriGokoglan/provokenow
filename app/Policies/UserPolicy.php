<?php

namespace App\Policies;

use App\Models\User;

/**
 * Admin panel rules for managing accounts:
 *  - superadmins manage everyone (except deleting themselves);
 *  - admins manage customer accounts only and cannot create or promote staff.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isStaff();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->isStaff();
    }

    public function create(User $actor): bool
    {
        return $actor->isStaff();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isSuperAdmin() || ($actor->isStaff() && ! $user->isStaff());
    }

    public function changeRole(User $actor, User $user): bool
    {
        return $actor->isSuperAdmin() && ! $actor->is($user);
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->isSuperAdmin() && ! $actor->is($user);
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
