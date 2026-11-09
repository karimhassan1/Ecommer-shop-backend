<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Order;
use Nodesol\LaraQL\Attributes\Query;

/**
 * The signed in user's own order history.
 *
 * What "own" means depends on the account:
 *
 *   customer   orders they placed
 *   seller     orders that contain at least one of their products
 *   staff/admin every order in the shop
 *
 * The storefront account page and the admin order list both read this query,
 * so the scoping is enforced in one place instead of per screen.
 */
#[Query(
    name: 'MyOrders',
    query: '@field(resolver:"MyOrders") @guard',
    filters: [
        'page: Int',
        'first: Int! = 20',
    ],
    return_type: 'OrderPaginator!',
)]
class MyOrders
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        $query = Order::query()
            ->with(['items', 'user'])
            ->when(
                $user->isSeller(),
                // A seller only fulfils the lines that belong to them.
                fn ($builder) => $builder->whereHas(
                    'items.product',
                    fn ($product) => $product->where('seller_id', $user->id)
                )
            )
            ->when(
                ! $user->isStaff(),
                fn ($builder) => $builder->where('user_id', $user->id)
            )
            ->latest();

        return $query->paginate(
            perPage: (int) ($args['first'] ?? 20),
            page: isset($args['page']) ? (int) $args['page'] : null,
        );
    }
}

