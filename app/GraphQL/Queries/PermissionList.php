<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\AuthorizesAdmin;
use Nodesol\LaraQL\Attributes\Query;
use Spatie\Permission\Models\Permission;

/**
 * The whole permission catalogue, grouped by resource, so the admin role editor
 * can render a matrix of checkboxes.
 */
#[Query(
    name: 'PermissionList',
    query: '@field(resolver:"PermissionList") @guard @permission(require: ["role.view"])',
    filters: [],
    return_type: '[PermissionGroup!]!',
)]
class PermissionList
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        return Permission::query()
            ->where('guard_name', 'sanctum')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission): string => explode('.', $permission->name)[0])
            ->map(fn ($permissions, string $resource): array => [
                'resource' => $resource,
                'permissions' => $permissions->map(fn (Permission $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'action' => explode('.', $p->name)[1] ?? '',
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
