<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Category;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Categories grouped for the storefront navigation.
 *
 * `type` lets the shop switch between the clothing and perfume catalogues, and
 * `product_count` powers the little "(12)" counters next to each name.
 */
#[Query(
    name: 'CategoryList',
    query: '@field(resolver:"CategoryList")',
    filters: [
        'type: String',
    ],
    return_type: '[Category!]!',
)]
class CategoryList
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        return Category::query()
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->when(
                isset($args['type']) && $args['type'] !== '',
                fn ($query) => $query->where('type', $args['type'])
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
