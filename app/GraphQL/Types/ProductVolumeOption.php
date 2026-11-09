<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * A volume a perfume is sold in, as exposed on `Product.volumes`.
 *
 * This is the flat list the perfume product page renders as buttons instead of
 * the colour → sizes tree used by clothing.
 */
#[Type(
    name: 'ProductVolumeOption',
    columns: [
        'variant_id' => 'ID!',
        'volume_id' => 'ID!',
        'label' => 'String!',
        'ml' => 'Float!',
        'in_stock' => 'Boolean!',
    ],
)]
class ProductVolumeOption
{
    public function __construct(
        public int $variant_id = 0,
        public int $volume_id = 0,
        public string $label = '',
        public float $ml = 0.0,
        public bool $in_stock = false,
    ) {}
}
