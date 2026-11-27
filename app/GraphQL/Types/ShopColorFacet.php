<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * One colour in the shop filter sidebar, with the swatch the customer clicks
 * and how many products come in it.
 *
 * `id` is what the shop puts in the URL (`colors=3,7`), `hex` is what the
 * swatch is painted with.
 */
#[Type(
    name: 'ShopColorFacet',
    columns: [
        'id' => 'ID!',
        'name' => 'String!',
        'hex' => 'String',
        'product_count' => 'Int!',
    ],
)]
class ShopColorFacet
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $hex = null,
        public int $product_count = 0,
    ) {}
}
