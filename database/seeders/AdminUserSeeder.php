<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class AdminUserSeeder extends Seeder
{
    /**
     * Demo accounts.
     *
     * The admin gets the `super-admin` role (which implicitly holds every
     * permission), and one staff account per default role so the role based
     * access can be exercised straight away.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Make sure the roles exist even when this seeder runs on its own.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        $admin = User::updateOrCreate(
            ['email' => 'admin@roserabloom.test'],
            [
                'name' => 'Rosera Admin',
                'phone' => '0300 1234567',
                'password' => 'password',
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->syncRoles([RoleSeeder::SUPER_ADMIN]);
        $admin->cartOrCreate();

        // One demo account per staff role, so you can sign in and see exactly
        // what that role can reach.
        $staff = [
            ['manager@roserabloom.test', 'Store Manager', RoleSeeder::MANAGER],
            ['catalog@roserabloom.test', 'Catalog Editor', RoleSeeder::CATALOG],
            ['support@roserabloom.test', 'Support Agent', RoleSeeder::SUPPORT],
            ['seller@roserabloom.test', 'Amina Seller', RoleSeeder::SELLER],
        ];

        foreach ($staff as [$email, $name, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'phone' => '0300 0000000',
                    'password' => 'password',
                    // Staff accounts are flagged so they pass the panel gate;
                    // being flagged is NOT the same as holding super-admin.
                    'is_admin' => $role !== RoleSeeder::SELLER,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$role]);
            $user->cartOrCreate();
        }

        $customer = User::updateOrCreate(
            ['email' => 'customer@roserabloom.test'],
            [
                'name' => 'Isabella Roman',
                'phone' => '0300 7654321',
                'password' => 'password',
                'is_admin' => false,
                'email_verified_at' => now(),
            ]
        );

        // No role: a plain customer with storefront access only.
        $customer->syncRoles([]);
        $customer->cartOrCreate();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
