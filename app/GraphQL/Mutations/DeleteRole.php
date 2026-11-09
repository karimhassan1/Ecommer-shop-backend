<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use Database\Seeders\RoleSeeder;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Admin only: delete a role that nobody holds any more. */
#[Type(columns: ['message' => 'String!', 'deleted_id' => 'ID'])]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"DeleteRole") @guard @permission(require: ["role.delete"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
    ],
)]
class DeleteRole
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
            throw new UserError('The super-admin role cannot be deleted.');
        }

        if ($role->users()->exists()) {
            throw new UserError('Remove this role from its users before deleting it.');
        }

        $id = $role->id;
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['message' => 'Role deleted successfully.', 'deleted_id' => $id];
    }
}
