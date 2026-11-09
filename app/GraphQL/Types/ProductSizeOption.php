<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * One size of one colour, as exposed on `Product.colors`.
 *
 * `variant_id` is what the storefront selects when the customer clicks the
 * size, which is what makes the gallery and price swap.
 */
#[Type(
    name: 'ProductSizeOption',
    columns: [
        'variant_id' => 'ID!',
        'size_id' => 'ID',
        'size' => 'String!',
        'sort_order' => 'Int!',
        'in_stock' => 'Boolean!',
    ],
)]
class ProductSizeOption
{
    public function __construct(
        public int $variant_id = 0,
        public ?int $size_id = null,
        public string $size = '',
        public int $sort_order = 0,
        public bool $in_stock = false,
    ) {}
}
