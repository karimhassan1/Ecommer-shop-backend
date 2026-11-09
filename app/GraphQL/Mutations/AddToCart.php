<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\CartService;
use Nodesol\LaraQL\Attributes\Mutation;

#[Mutation(
    name: ' ',
    query: '@field(resolver:"AddToCart") @guard',
    inputs: [
        'product_id' => 'ID! @rules(apply:["required"])',
        'product_variant_id' => 'ID',
        'quantity' => 'Int! = 1 @rules(apply:["required","integer","min:1"])',
    ],
    return_type: 'Cart!',
)]
class AddToCart
{
    public function __construct(private readonly CartService $cart) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $cart = $this->cart->addItem(
            auth()->user(),
            (int) $args['product_id'],
            (int) ($args['quantity'] ?? 1),
            isset($args['product_variant_id']) ? (int) $args['product_variant_id'] : null,
        );

        return $cart;
    }
}
