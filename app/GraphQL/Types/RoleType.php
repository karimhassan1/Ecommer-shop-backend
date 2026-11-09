<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/** A role with the permission names it holds. */
#[Type(
    name: 'Role',
    columns: [
        'id' => 'ID!',
        'name' => 'String!',
        'guard_name' => 'String!',
        'permissions' => '[String!]!',
        'users_count' => 'Int!',
        'is_locked' => 'Boolean!',
    ],
)]
class RoleType
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $guard_name = 'web',
        public array $permissions = [],
        public int $users_count = 0,
        public bool $is_locked = false,
    ) {}
}
