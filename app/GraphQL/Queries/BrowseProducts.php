<?php

declare(strict_types=1);

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
        'audience: String',
        'category_id: ID',
        'category_slug: String',
        'brand_id: ID',
        'brand_slug: String',
        'brand_ids: [ID!]',
        'fabric_type_id: ID',
        'fabric_type_slug: String',
        'fabric_type_ids: [ID!]',
        'color_ids: [ID!]',
        'seasons: [String!]',
        'fabric_qualities: [String!]',
        'min_price: Float',
        'max_price: Float',
        'in_stock_only: Boolean! = false',
        'on_sale_only: Boolean! = false',
        'sizes: [String!]',
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
                'fabricType',
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
            // "Men's / Women's / Unisex" tabs: a unisex product is shown in its
            // own tab only, never duplicated into both gendered ones.
            ->when(
                ! empty($args['audience']),
                fn ($builder) => $builder->where('audience', $args['audience'])
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
            ->when(
                ! empty($args['brand_id']),
                fn ($builder) => $builder->whereHas(
                    'brands',
                    fn ($q) => $q->whereKey((int) $args['brand_id'])
                )
            )
            ->when(
                ! empty($args['brand_slug']),
                fn ($builder) => $builder->whereHas(
                    'brands',
                    fn ($q) => $q->where('slug', $args['brand_slug'])
                )
            )
            // Multi-select brand chips: any of the selected labels matches.
            ->when(
                ! empty($args['brand_ids']),
                function ($builder) use ($args): void {
                    $ids = array_values(array_filter((array) $args['brand_ids']));

                    $builder->whereHas(
                        'brands',
                        fn ($q) => $q->whereKey($ids)
                    );
                }
            )
            // "Fabric Type" (clothing only). A perfume never carries one, so
            // this filter simply excludes every perfume — which is correct, as
            // the facet is hidden on the fragrance page.
            ->when(
                ! empty($args['fabric_type_id']),
                fn ($builder) => $builder->where('fabric_type_id', (int) $args['fabric_type_id'])
            )
            ->when(
                ! empty($args['fabric_type_ids']),
                function ($builder) use ($args): void {
                    $ids = array_values(array_filter((array) $args['fabric_type_ids']));

                    $builder->whereIn('fabric_type_id', $ids);
                }
            )
            ->when(
                ! empty($args['fabric_type_slug']),
                fn ($builder) => $builder->whereHas(
                    'fabricType',
                    fn ($q) => $q->where('slug', $args['fabric_type_slug'])
                )
            )
            // Colour swatches: the product must come in at least one of the
            // selected colours.
            ->when(
                ! empty($args['color_ids']),
                function ($builder) use ($args): void {
                    $ids = array_values(array_filter((array) $args['color_ids']));

                    $builder->whereHas('variants', function ($q) use ($ids): void {
                        $q->where('is_active', true)->whereIn('color_id', $ids);
                    });
                }
            )
            // Season: "All season" pieces match a summer *and* a winter filter,
            // which is what a customer expects from a year-round garment.
            ->when(
                ! empty($args['seasons']),
                function ($builder) use ($args): void {
                    $seasons = array_values(array_filter((array) $args['seasons']));

                    $builder->where(function ($match) use ($seasons): void {
                        $match->whereIn('season', $seasons);

                        if (in_array(Product::SEASON_ALL, $seasons, true)) {
                            return;
                        }

                        // Only when the customer did not ask for all-season do
                        // the year-round pieces join the result.
                        $match->orWhere('season', Product::SEASON_ALL);
                    });
                }
            )
            ->when(
                ! empty($args['fabric_qualities']),
                function ($builder) use ($args): void {
                    $values = array_values(array_filter((array) $args['fabric_qualities']));

                    $builder->whereIn('fabric_quality', $values);
                }
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
            // "On sale" means at least one active variant has a usable discount.
            ->when($args['on_sale_only'] ?? false, function ($builder): void {
                $builder->whereHas('variants', function ($q): void {
                    $q->where('is_active', true)
                        ->whereNotNull('discount_price')
                        ->whereColumn('discount_price', '<', 'price')
                        ->where(function ($window): void {
                            $window->whereNull('discount_starts_at')
                                ->orWhere('discount_starts_at', '<=', now());
                        })
                        ->where(function ($window): void {
                            $window->whereNull('discount_ends_at')
                                ->orWhere('discount_ends_at', '>=', now());
                        });
                });
            })
            ->when($search !== '', function ($builder) use ($search): void {
                $term = '%'.strtolower($search).'%';

                $builder->where(function ($nested) use ($term): void {
                    $nested->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(description, \'\')) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(fabric, \'\')) like ?', [$term]);
                });
            })
            // Size / volume chips: a product matches when any active variant
            // carries one of the selected labels.
            ->when(
                ! empty($args['sizes']),
                function ($builder) use ($args): void {
                    $labels = array_values(array_filter((array) $args['sizes']));

                    $builder->whereHas('variants', function ($q) use ($labels): void {
                        $q->where('is_active', true)->where(function ($match) use ($labels): void {
                            $match->whereHas('size', fn ($size) => $size->whereIn('name', $labels))
                                ->orWhereHas('volume', fn ($volume) => $volume->whereIn('label', $labels));
                        });
                    });
                }
            );

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
                $regular->where(function ($when): void {
                    // No usable discount: compare the regular price.
                    $when->whereNull('discount_price')
                        ->orWhereColumn('discount_price', '>=', 'price');
                })->where('price', $operator, $amount);
            });
        });
    }
}
