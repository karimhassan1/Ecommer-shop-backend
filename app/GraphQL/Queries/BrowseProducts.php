<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Product;
use App\Models\ProductVariant;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Storefront catalog listing with search, type, category, price range and
 * pagination.
 *
 * Price and stock live on the variants, so both filters are expressed through
 * the `variants` relation: a product matches a price range when *any* variant
 * falls inside it, and a sort by price orders by the cheapest variant.
 *
 * Implemented as a resolver so the search is case-insensitive on SQLite and
 * MySQL alike.
 */
#[Query(
    name: 'BrowseProducts',
    query: '@field(resolver:"BrowseProducts")',
    filters: [
        'search: String',
        'type: String',
        'category_id: ID',
        'category_slug: String',
        'min_price: Float',
        'max_price: Float',
        'in_stock_only: Boolean! = false',
        'include_inactive: Boolean! = false',
        'featured_only: Boolean! = false',
        'mine: Boolean! = false',
        'page: Int',
        'first: Int! = 12',
        'order_by: String = "newest"',
    ],
    return_type: 'ProductPaginator!',
)]
class BrowseProducts
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $search = trim((string) ($args['search'] ?? ''));
        $user = auth()->user();

        $query = Product::query()
            ->with([
                'brands',
                'categories',
                'seller',
                'variants.color',
                'variants.size',
                'variants.volume',
                'variants.images',
            ])
            ->unless($args['include_inactive'] ?? false, fn ($builder) => $builder->where('is_active', true))
            // The home page asks for the merchandised rows only; the shop
            // listing never sends this, so it keeps showing everything.
            ->when(
                $args['featured_only'] ?? false,
                fn ($builder) => $builder->where('is_featured', true)
            )
            // `mine` is what the admin product table sends. It narrows the
            // listing to the signed in user's own rows, which for a seller is
            // their whole catalogue and for staff is their own listings only.
            ->when(
                ($args['mine'] ?? false) && $user,
                fn ($builder) => $builder->where('seller_id', $user->id)
            )
            // A seller who opens the admin table without `mine` still only ever
            // sees their own catalogue: the scope is a no-op for staff.
            ->when(
                ! ($args['mine'] ?? false) && $user?->isSeller(),
                fn ($builder) => $builder->where('seller_id', $user->id)
            )
            ->when(
                ! empty($args['type']),
                fn ($builder) => $builder->where('type', $args['type'])
            )
            ->when(
                ! empty($args['category_id']),
                fn ($builder) => $builder->whereHas(
                    'categories',
                    fn ($q) => $q->whereKey((int) $args['category_id'])
                )
            )
            ->when(
                ! empty($args['category_slug']),
                fn ($builder) => $builder->whereHas(
                    'categories',
                    fn ($q) => $q->where('slug', $args['category_slug'])
                )
            )
            // A range matches the variant the customer could actually buy, so
            // the discount price is what the shop filters on. Column-to-value
            // comparisons are used (rather than a CASE expression) so the
            // column's numeric affinity applies on every driver.
            ->when($args['min_price'] ?? null, fn ($builder, $min) => $builder->whereHas(
                'variants',
                fn ($q) => $this->matchesPrice($q, '>=', (float) $min)
            ))
            ->when($args['max_price'] ?? null, fn ($builder, $max) => $builder->whereHas(
                'variants',
                fn ($q) => $this->matchesPrice($q, '<=', (float) $max)
            ))
            ->when($args['in_stock_only'] ?? false, function ($builder): void {
                $builder->whereHas(
                    'variants',
                    fn ($q) => $q->where('stock', '>', 0)->where('is_active', true)
                );
            })
            ->when($search !== '', function ($builder) use ($search): void {
                $term = '%'.strtolower($search).'%';

                $builder->where(function ($nested) use ($term): void {
                    $nested->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(description, \'\')) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(fabric, \'\')) like ?', [$term]);
                });
            });

        match ((string) ($args['order_by'] ?? 'newest')) {
            'price_asc' => $query->orderBy(
                ProductVariant::query()
                    ->selectRaw('min(price)')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
            ),
            'price_desc' => $query->orderByDesc(
                ProductVariant::query()
                    ->selectRaw('min(price)')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
            ),
            'name_asc' => $query->orderBy('name'),
            'rating' => $query->orderByDesc('rating_average')->orderByDesc('rating_count'),
            'oldest' => $query->oldest(),
            default => $query->latest(),
        };

        return $query->paginate(
            perPage: (int) ($args['first'] ?? 12),
            page: isset($args['page']) ? (int) $args['page'] : null,
        );
    }

    /**
     * Restrict the variants sub-query to the ones whose effective price
     * (discount applied) satisfies the range.
     *
     * The comparison is written against the columns themselves because a raw
     * CASE expression carries no affinity and would compare as text on SQLite.
     */
    protected function matchesPrice($query, string $operator, float $amount): void
    {
        $query->where(function ($nested) use ($operator, $amount): void {
            $nested->where(function ($discounted) use ($operator, $amount): void {
                $discounted->whereNotNull('discount_price')
                    ->whereColumn('discount_price', '<', 'price')
                    ->where('discount_price', $operator, $amount);
            })->orWhere(function ($regular) use ($operator, $amount): void {
                $regular->where(function ($when) use ($operator, $amount): void {
                    // No usable discount: compare the regular price.
                    $when->whereNull('discount_price')
                        ->orWhereColumn('discount_price', '>=', 'price');
                })->where('price', $operator, $amount);
            });
        });
    }
}
