<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/** One resource's permissions, e.g. all the `product.*` abilities. */
#[Type(
    name: 'PermissionGroup',
    columns: [
        'resource' => 'String!',
        'permissions' => '[Permission!]!',
    ],
)]
class PermissionGroup
{
    /**
     * @param  array<int, array{id: int, name: string, action: string}>  $permissions
     */
    public function __construct(
        public string $resource,
        public array $permissions = [],
    ) {}
}
