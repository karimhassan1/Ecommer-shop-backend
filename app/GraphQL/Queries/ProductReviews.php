<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Review;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Approved reviews of a single product, newest first.
 *
 * Public on purpose: reviews are a storefront feature, but only approved ones
 * are ever returned so admin moderation actually hides content.
 */
#[Query(
    name: 'ProductReviews',
    query: '@field(resolver:"ProductReviews")',
    filters: [
        'product_id: ID!',
        'page: Int',
        'first: Int! = 10',
    ],
    return_type: 'ReviewPaginator!',
)]
class ProductReviews
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        return Review::query()
            ->with('user')
            ->where('product_id', (int) $args['product_id'])
            ->where('is_approved', true)
            ->latest()
            ->paginate(
                perPage: (int) ($args['first'] ?? 10),
                page: isset($args['page']) ? (int) $args['page'] : null,
            );
    }
}
