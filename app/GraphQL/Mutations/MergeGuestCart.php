<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\CartService;
use Nodesol\LaraQL\Attributes\Mutation;

#[Mutation(
    name: ' ',
    query: '@field(resolver:"MergeGuestCart") @guard',
    inputs: [
        'items' => '[GuestCartLineInput!]!',
    ],
    return_type: 'Cart!',
)]
class MergeGuestCart
{
    public function __construct(private readonly CartService $cart) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $lines = array_map(
            static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
                'product_variant_id' => isset($line['product_variant_id'])
                    ? (int) $line['product_variant_id']
                    : null,
            ],
            $args['items'] ?? []
        );

        return $this->cart->mergeGuest(auth()->user(), $lines);
    }
}
