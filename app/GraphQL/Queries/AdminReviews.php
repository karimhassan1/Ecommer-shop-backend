<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Review;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Admin listing of every review, including those awaiting moderation.
 */
#[Query(
    name: 'AdminReviews',
    query: '@field(resolver:"AdminReviews") @guard @permission(require: ["review.view"])',
    filters: [
        'product_id: ID',
        'is_approved: Boolean',
        'page: Int',
        'first: Int! = 20',
    ],
    return_type: 'ReviewPaginator!',
)]
class AdminReviews
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        return Review::query()
            ->with(['user', 'product'])
            ->when(
                isset($args['product_id']),
                fn ($query, $id) => $query->where('product_id', (int) $id)
            )
            ->when(
                array_key_exists('is_approved', $args) && $args['is_approved'] !== null,
                fn ($query) => $query->where('is_approved', (bool) $args['is_approved'])
            )
            ->latest()
            ->paginate(
                perPage: (int) ($args['first'] ?? 20),
                page: isset($args['page']) ? (int) $args['page'] : null,
            );
    }
}
