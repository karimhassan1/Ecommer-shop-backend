<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\AuthorizesAdmin;
use Nodesol\LaraQL\Attributes\Query;
use Spatie\Permission\Models\Role;

/**
 * Every role with the permissions it holds, for the admin role editor.
 */
#[Query(
    name: 'RoleList',
    query: '@field(resolver:"RoleList") @guard @permission(require: ["role.view"])',
    filters: [],
    return_type: '[Role!]!',
)]
class RoleList
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        // Roles exist per guard (web + sanctum); the panel only manages the
        // guard the authenticated staff member belongs to.
        return Role::query()
            ->where('guard_name', 'sanctum')
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'users_count' => (int) $role->users_count,
                'is_locked' => $role->name === \Database\Seeders\RoleSeeder::SUPER_ADMIN,
            ])
            ->all();
    }
}
