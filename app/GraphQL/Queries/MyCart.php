<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Services\CartService;
use Nodesol\LaraQL\Attributes\Query;

/**
 * The cart of the authenticated user, with variant details and totals.
 */
#[Query(
    name: 'MyCart',
    query: '@field(resolver:"MyCart") @guard',
    filters: [],
    return_type: 'Cart!',
)]
class MyCart
{
    public function __construct(private readonly CartService $carts) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        return $this->carts->load($this->carts->for(auth()->user()));
    }
}
