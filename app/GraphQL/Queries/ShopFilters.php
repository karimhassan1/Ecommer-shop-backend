<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\FabricType;
use App\Models\Product;
use App\Models\ProductVariant;
use Nodesol\LaraQL\Attributes\Query;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Facets for the shop filter sidebar.
 *
 * The listing itself is `BrowseProducts`; this query only answers "what can I
 * filter by?" so the sidebar shows the real catalogue instead of a hard coded
 * list: the price slider learns its min/max from the variants, brand and
 * category counts respect the selected product type, and a category whose
 * counter is zero is hidden.
 */
#[Type(
    columns: [
        'min_price' => 'Float!',
        'max_price' => 'Float!',
        'brands' => '[ShopFacet!]!',
        'categories' => '[ShopFacet!]!',
        'fabric_types' => '[ShopFacet!]!',
        'colors' => '[ShopColorFacet!]!',
        'seasons' => '[ShopOptionFacet!]!',
        'fabric_qualities' => '[ShopOptionFacet!]!',
        'sizes' => '[String!]!',
    ]
)]
#[Query(
    name: 'ShopFilters',
    query: '@field(resolver:"ShopFilters")',
    filters: [
        'type: String',
    ],
)]
class ShopFilters
{
    /** @param array<string, mixed> $args */
    public function __invoke($root, array $args)
    {
        $type = $args['type'] ?? null;
        $type = is_string($type) && $type !== '' ? $type : null;

        $productScope = fn ($query) => $query
            ->where('is_active', true)
            ->when($type !== null, fn ($q) => $q->where('type', $type));

        // Prices live on variants, so the slider bounds come from there.
        $price = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', $productScope)
            ->selectRaw('min(coalesce(discount_price, price)) as min_price, max(coalesce(discount_price, price)) as max_price')
            ->first();

        return [
            'min_price' => (float) ($price->min_price ?? 0),
            'max_price' => (float) ($price->max_price ?? 0),
            'brands' => $this->brands($productScope),
            'categories' => $this->categories($productScope, $type),
            // Clothing only: a perfume is never made of a fabric, so the
            // fragrance page gets empty lists instead of facets nobody can use.
            'fabric_types' => $this->fabricTypes($productScope, $type),
            'colors' => $this->colors($productScope, $type),
            'seasons' => $this->optionFacets($productScope, $type, 'season', config('shop.seasons', [])),
            'fabric_qualities' => $this->optionFacets($productScope, $type, 'fabric_quality', config('shop.fabric_qualities', [])),
            'sizes' => $this->sizes($type),
        ];
    }

    /**
     * Brands that have at least one active product, with their product count.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function brands(callable $productScope): array
    {
        return Brand::query()
            ->whereHas('products', $productScope)
            ->withCount(['products' => $productScope])
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand): array => [
                'id' => (int) $brand->id,
                'name' => (string) $brand->name,
                'slug' => (string) $brand->id,
                'product_count' => (int) $brand->products_count,
            ])
            ->all();
    }

    /**
     * Categories of the selected type that hold at least one active product.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function categories(callable $productScope, ?string $type): array
    {
        return Category::query()
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->whereHas('products', $productScope)
            ->withCount(['products' => $productScope])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'product_count' => (int) $category->products_count,
            ])
            ->all();
    }

    /**
     * Size (or volume) labels in use, for the "size" filter chips.
     *
     * @return array<int, string>
     */
    protected function sizes(?string $type): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->with(['variants.size', 'variants.volume'])
            ->get();

        return $products
            ->flatMap(fn (Product $product): array => $product->variants
                ->map(fn (ProductVariant $variant): ?string => $variant->size?->name ?? $variant->volume?->label)
                ->filter()
                ->all())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Fabrics the catalogue is actually made of, with their product count.
     *
     * Clothing only: perfume has no fabric, so the fragrance page receives an
     * empty list and its sidebar omits the block entirely.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fabricTypes(callable $productScope, ?string $type): array
    {
        if ($type === Product::TYPE_PERFUME) {
            return [];
        }

        return FabricType::query()
            ->where('is_active', true)
            ->whereHas('products', $productScope)
            ->withCount(['products' => $productScope])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (FabricType $fabric): array => [
                'id' => (int) $fabric->id,
                'name' => (string) $fabric->name,
                'slug' => (string) ($fabric->slug ?? $fabric->id),
                'product_count' => (int) $fabric->products_count,
            ])
            ->all();
    }

    /**
     * Colours the catalogue comes in, with how many products carry each.
     *
     * The count is per product (not per variant), so a product available in
     * three sizes of one colour adds 1 to that colour, not 3.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function colors(callable $productScope, ?string $type): array
    {
        return Color::query()
            ->where('is_active', true)
            ->whereHas('variants.product', $productScope)
            ->withCount(['variants as product_count' => fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('product', $productScope)
                ->selectRaw('count(distinct product_id)'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (Color $color): bool => (int) $color->product_count > 0)
            ->map(fn (Color $color): array => [
                'id' => (int) $color->id,
                'name' => (string) $color->name,
                'hex' => $color->hex,
                'product_count' => (int) $color->product_count,
            ])
            ->values()
            ->all();
    }

    /**
     * A fixed-list facet (`season`, `fabric_quality`) with its product counts.
     *
     * Only values that at least one product actually uses are returned, so the
     * customer never clicks a chip that leads to an empty listing. The order and
     * the labels come from `config('shop.*')`, so the panel, the API and the
     * storefront can never disagree about what "all-season" is called.
     *
     * @param  array<string, string>  $labels  value => label
     * @return array<int, array<string, mixed>>
     */
    protected function optionFacets(callable $productScope, ?string $type, string $column, array $labels): array
    {
        // Perfume carries neither season nor fabric quality.
        if ($type === Product::TYPE_PERFUME) {
            return [];
        }

        $counts = Product::query()
            ->whereNotNull($column)
            ->tap($productScope)
            ->selectRaw("{$column} as value, count(*) as product_count")
            ->groupBy($column)
            ->pluck('product_count', 'value')
            ->all();

        $rows = [];

        foreach ($labels as $value => $label) {
            $count = (int) ($counts[$value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $rows[] = [
                'value' => (string) $value,
                'label' => (string) $label,
                'product_count' => $count,
            ];
        }

        return $rows;
    }
}
