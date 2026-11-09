<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/** A single permission, e.g. `product.update`. */
#[Type(
    name: 'Permission',
    columns: [
        'id' => 'ID!',
        'name' => 'String!',
        'action' => 'String!',
    ],
)]
class PermissionType
{
    public function __construct(
        public int $id,
        public string $name,
        public string $action = '',
    ) {}
}
