<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Brand;
use App\Models\Category;
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
}
