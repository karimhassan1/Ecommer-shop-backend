<?php

namespace App\Policies;

use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Permission driven base policy.
 *
 * Each concrete policy declares the resource name, and the four standard
 * abilities map onto `resource.view`, `resource.create`, `resource.update` and
 * `resource.delete`. Custom abilities (approve, update-status, …) are added as
 * extra methods in the concrete policy.
 */
abstract class PermissionPolicy
{
    /** The permission prefix, e.g. "product" for `product.update`. */
    protected string $resource = '';

    /**
     * Super admins pass every check; everybody else is evaluated against the
     * permission they hold.
     */
    public function before(User $user): ?bool
    {
        if ($user->hasRole(RoleSeeder::SUPER_ADMIN) || (bool) $user->is_admin) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'delete');
    }

    /**
     * Check `<resource>.<ability>`.
     */
    protected function allows(User $user, string $ability): bool
    {
        if ($this->resource === '') {
            return false;
        }

        return $user->can("{$this->resource}.{$ability}");
    }
}
