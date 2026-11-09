<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * A colour a product is available in, as exposed on `Product.colors`.
 *
 * Declared as a type-only class: LaraQL emits the SDL from the attribute and
 * nothing ever instantiates it.
 */
#[Type(
    name: 'ColorOption',
    columns: [
        'name' => 'String!',
        'hex' => 'String',
    ],
)]
class ColorOption
{
    public function __construct(
        public string $name,
        public ?string $hex = null,
    ) {}
}
