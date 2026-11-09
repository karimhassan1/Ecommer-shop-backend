<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * A selectable product type (clothing / perfume) with its display label.
 */
#[Type(
    name: 'ProductType',
    columns: [
        'value' => 'String!',
        'label' => 'String!',
    ],
)]
class ProductType
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
