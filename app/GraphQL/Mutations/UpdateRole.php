<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use Database\Seeders\RoleSeeder;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin only: rename a role or change the permissions it grants.
 *
 * The `super-admin` role is locked because it implicitly holds every
 * permission through a Gate::before hook.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"UpdateRole") @guard @permission(require: ["role.update"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'permissions' => '[String!]',
    ],
    return_type: 'Role!',
)]
class UpdateRole
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var Role|null $role */
        $role = Role::find($args['id']);

        if (! $role) {
            throw new UserError('That role could not be found.');
        }

        if ($role->name === RoleSeeder::SUPER_ADMIN) {
            throw new UserError('The super-admin role always has every permission.');
        }

        if (array_key_exists('permissions', $args) && $args['permissions'] !== null) {
            $role->syncPermissions($args['permissions']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->fresh()->permissions->pluck('name')->values()->all(),
            'users_count' => $role->users()->count(),
            'is_locked' => false,
        ];
    }
}
