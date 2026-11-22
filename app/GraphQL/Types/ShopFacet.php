<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * One filter option in the shop sidebar: a brand or a category together with
 * how many products it holds.
 *
 * `value` is what the shop puts in the URL (`brand=3`, `category=pret`), so the
 * same shape works for both lists and the front end needs one component.
 */
#[Type(
    name: 'ShopFacet',
    columns: [
        'id' => 'ID!',
        'name' => 'String!',
        'slug' => 'String!',
        'product_count' => 'Int!',
    ],
)]
class ShopFacet
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug = '',
        public int $product_count = 0,
    ) {}
}
