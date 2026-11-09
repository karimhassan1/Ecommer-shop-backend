<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Admin only: create a role and attach the chosen permissions. */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"CreateRole") @guard @permission(require: ["role.create"])',
    inputs: [
        'name' => 'String! @rules(apply:["required","max:60","alpha_dash"])',
        'permissions' => '[String!]',
    ],
    return_type: 'Role!',
)]
class CreateRole
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $name = strtolower(trim((string) $args['name']));

        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            throw new UserError('Use lowercase letters, numbers and dashes only.');
        }

        if (Role::where('name', $name)->where('guard_name', 'sanctum')->exists()) {
            throw new UserError('A role with that name already exists.');
        }

        $role = Role::findOrCreate($name, 'sanctum');

        $permissions = $args['permissions'] ?? [];

        if ($permissions) {
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'users_count' => 0,
            'is_locked' => false,
        ];
    }
}
