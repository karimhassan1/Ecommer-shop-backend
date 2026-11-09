<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * A colour a clothing product is available in, with the sizes it comes in.
 *
 * Exposed on `Product.colors`; the storefront renders one swatch per colour and
 * then the `sizes` list underneath it.
 */
#[Type(
    name: 'ProductColorGroup',
    columns: [
        'id' => 'ID!',
        'name' => 'String!',
        'hex' => 'String',
        'sizes' => '[ProductSizeOption!]!',
    ],
)]
class ProductColorGroup
{
    /**
     * @param  array<int, ProductSizeOption|array<string, mixed>>  $sizes
     */
    public function __construct(
        public int $id = 0,
        public string $name = '',
        public ?string $hex = null,
        public array $sizes = [],
    ) {}
}
