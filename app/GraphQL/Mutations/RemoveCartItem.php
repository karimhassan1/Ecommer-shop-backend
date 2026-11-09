<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\CartService;
use Nodesol\LaraQL\Attributes\Mutation;

#[Mutation(
    name: ' ',
    query: '@field(resolver:"RemoveCartItem") @guard',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
    ],
    return_type: 'Cart!',
)]
class RemoveCartItem
{
    public function __construct(private readonly CartService $cart) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        $this->cart->removeItem($user, (int) $args['id']);

        return $this->cart->load($this->cart->for($user));
    }
}
