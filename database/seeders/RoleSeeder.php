<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default roles.
 *
 * `super-admin` receives every permission through a Gate::before hook (see
 * AppServiceProvider), so newly added permissions are available to it without
 * re-seeding. The other roles get an explicit, editable set.
 */
class RoleSeeder extends Seeder
{
    public const SUPER_ADMIN = 'super-admin';

    public const MANAGER = 'manager';

    public const CATALOG = 'catalog-manager';

    public const SUPPORT = 'support';

    /**
     * A seller trades their own catalogue.
     *
     * The role is deliberately narrow: it can build products, read the orders
     * that contain them and move those orders forward, nothing else. Every
     * query and mutation additionally filters by `products.seller_id`, so the
     * permissions only describe the *kind* of work a seller does.
     */
    public const SELLER = 'seller';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            self::SUPER_ADMIN => [], // gets everything via Gate::before
            self::MANAGER => [
                'dashboard.view',
                'product.view', 'product.create', 'product.update', 'product.delete',
                'brand.view', 'brand.create', 'brand.update', 'brand.delete',
                'category.view', 'category.create', 'category.update', 'category.delete',
                'order.view', 'order.view-all', 'order.update', 'order.update-status',
                'review.view', 'review.approve', 'review.update', 'review.delete',
                'message.view', 'message.reply', 'message.update', 'message.delete',
                'user.view',
                'setting.view',
            ],
            self::CATALOG => [
                'dashboard.view',
                'product.view', 'product.create', 'product.update', 'product.delete',
                'brand.view', 'brand.create', 'brand.update', 'brand.delete',
                'category.view', 'category.create', 'category.update', 'category.delete',
                'review.view',
            ],
            self::SUPPORT => [
                'dashboard.view',
                'order.view', 'order.view-all', 'order.update-status',
                'review.view', 'review.approve',
                'message.view', 'message.reply', 'message.update',
                'user.view',
                'product.view',
            ],
            self::SELLER => [
                'dashboard.view',
                'product.view', 'product.create', 'product.update', 'product.delete',
                // A seller files their own products under a brand and a
                // category, so they must be able to *read* both lists. The
                // queries narrow every list to the rows their own catalogue
                // uses, so `brand.view` here never exposes another seller's
                // labels.
                'brand.view',
                'category.view',
                'order.view', 'order.update-status',
                'review.view',
            ],
        ];

        foreach ($roles as $name => $permissions) {
            foreach (['web', 'sanctum'] as $guard) {
                $role = Role::findOrCreate($name, $guard);

                if ($permissions) {
                    $role->syncPermissions($permissions);
                }
            }
        }

        // Every existing administrator gets the super-admin role.
        User::query()->where('is_admin', true)->get()->each(
            fn (User $user) => $user->assignRole(self::SUPER_ADMIN)
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
