<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers for tests that need a user with staff access.
 *
 * Permission data is seeded per test because every feature test runs inside a
 * `RefreshDatabase` transaction.
 */
trait CreatesStaffUsers
{
    protected bool $permissionsSeeded = false;

    /** Seed the permission catalogue and default roles exactly once. */
    protected function seedPermissions(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->permissionsSeeded = true;
    }

    /**
     * An administrator holding every permission (super-admin role).
     */
    protected function adminUser(array $attributes = []): User
    {
        $this->seedPermissions();

        $user = User::factory()->create(array_merge(['is_admin' => true], $attributes));
        $user->assignRole(RoleSeeder::SUPER_ADMIN);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * A staff member with one of the default roles.
     */
    protected function staffUser(string $role, array $attributes = []): User
    {
        $this->seedPermissions();

        $user = User::factory()->create(array_merge(['is_admin' => false], $attributes));
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
