<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\CartService;
use Nodesol\LaraQL\Attributes\Mutation;

#[Mutation(
    name: ' ',
    query: '@field(resolver:"UpdateCartItem") @guard',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'quantity' => 'Int! @rules(apply:["required","integer","min:1"])',
    ],
    return_type: 'Cart!',
)]
class UpdateCartItem
{
    public function __construct(private readonly CartService $cart) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        $this->cart->updateItem($user, (int) $args['id'], (int) $args['quantity']);

        return $this->cart->load($this->cart->for($user));
    }
}
