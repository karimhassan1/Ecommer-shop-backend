<?php

namespace App\Policies;

use App\Models\User;

/**
 * Shared admin gate used by every policy in the application.
 */
abstract class AdminPolicy
{
    /**
     * Admins bypass every ability.
     */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }
}
