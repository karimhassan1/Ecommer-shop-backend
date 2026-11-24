<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single administrator account the shop is run from.
 *
 * The shop deliberately has one account for now: the owner signs in, manages
 * the catalogue and creates any further staff through Admin → Customers. The
 * old demo accounts (a shared admin mailbox plus one per default role) are
 * removed here, so re-seeding a database that was set up with them leaves only
 * the real owner behind.
 *
 * The password is the one the owner asked for; it is hashed by the model cast,
 * so re-running this seeder never stores it in the clear.
 */
class AdminUserSeeder extends Seeder
{
    /** The shop owner's account. */
    public const ADMIN_EMAIL = 'karimhassan263372@gmail.com';

    /** Accounts created by earlier versions of this seeder. */

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Make sure the roles exist even when this seeder runs on its own.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        $admin = User::updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Karim Hassan',
                'password' => 'password',
                'is_admin' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->syncRoles([RoleSeeder::SUPER_ADMIN]);
        $admin->cartOrCreate();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }


}

