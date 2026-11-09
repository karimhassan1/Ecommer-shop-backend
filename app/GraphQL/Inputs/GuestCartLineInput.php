<?php declare(strict_types=1);

namespace App\GraphQL\Inputs;

use Nodesol\LaraQL\Attributes\Input;

/**
 * One line of a cart kept in the browser by a guest visitor.
 */
#[Input(
    name: 'GuestCartLineInput',
    inputs: [
        'product_id' => 'Int!',
        'product_variant_id' => 'Int',
        'quantity' => 'Int!',
    ],
)]
class GuestCartLineInput
{
    public function __construct(
        public int $product_id,
        public int $quantity,
        public ?int $product_variant_id = null,
    ) {}
}
