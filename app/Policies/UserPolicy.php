<?php

namespace App\Policies;

use App\Models\User;

/**
 * A user may always read and edit their own profile; staff need `user.*`.
 */
class UserPolicy extends PermissionPolicy
{
    protected string $resource = 'user';

    public function view(User $user, ?User $model = null): bool
    {
        if ($model && $model->id === $user->id) {
            return true;
        }

        return $this->allows($user, 'view');
    }

    public function update(User $user, ?User $model = null): bool
    {
        if ($model && $model->id === $user->id) {
            return true;
        }

        return $this->allows($user, 'update');
    }

    public function delete(User $user, ?User $model = null): bool
    {
        if ($model && $model->id === $user->id) {
            return $this->allows($user, 'delete');
        }

        return $this->allows($user, 'delete');
    }

    public function assignRole(User $user): bool
    {
        return $this->allows($user, 'assign-role');
    }
}
