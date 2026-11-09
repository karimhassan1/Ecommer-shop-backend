<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin only: replace the roles held by a user.
 *
 * Sending an empty list removes every role. A user without a role loses all
 * staff access, which is how access is revoked.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"AssignUserRole") @guard @permission(require: ["user.assign-role"])',
    inputs: [
        'user_id' => 'ID! @rules(apply:["required"])',
        'roles' => '[String!]!',
    ],
    return_type: 'User!',
)]
class AssignUserRole
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var User|null $user */
        $user = User::find($args['user_id']);

        if (! $user) {
            throw new UserError('That user could not be found.');
        }

        // Never let an admin strip their own access by accident.
        if ($user->id === auth()->id() && count($args['roles']) === 0) {
            throw new UserError('You cannot remove your own access.');
        }

        $user->syncRoles($args['roles']);

        // Only the super-admin role implies the legacy `is_admin` flag. A
        // limited role must NOT become an administrator, otherwise the
        // super-admin fallback would give it every permission.
        $user->forceFill([
            'is_admin' => $user->hasRole(RoleSeeder::SUPER_ADMIN),
        ])->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
