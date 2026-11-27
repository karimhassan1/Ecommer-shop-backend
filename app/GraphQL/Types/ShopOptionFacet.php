<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * One value-based filter option: season ("Summer"), fabric quality
 * ("Premium") and any other facet whose options are a fixed list of values
 * rather than rows in a table.
 *
 * `value` is what the shop puts in the URL (`season=summer`), `label` is what
 * the customer reads.
 */
#[Type(
    name: 'ShopOptionFacet',
    columns: [
        'value' => 'String!',
        'label' => 'String!',
        'product_count' => 'Int!',
    ],
)]
class ShopOptionFacet
{
    public function __construct(
        public string $value,
        public string $label,
        public int $product_count = 0,
    ) {}
}
