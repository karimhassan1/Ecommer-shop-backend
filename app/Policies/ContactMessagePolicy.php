<?php

namespace App\Policies;

use App\Models\User;

class ContactMessagePolicy extends PermissionPolicy
{
    protected string $resource = 'message';

    public function create(User $user): bool
    {
        // The public contact form submits through a dedicated mutation.
        return true;
    }

    public function reply(User $user): bool
    {
        return $this->allows($user, 'reply');
    }
}
