<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductVariantService;
use Illuminate\Support\Facades\DB;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: create a catalog product together with its variants and photos.
 *
 * The product row itself holds no price and no image. Everything buyable — the
 * price, the optional discount, the stock and the photos — is created on the
 * variants, and the whole tree is validated once, in ProductVariantService,
 * inside a single transaction.
 *
 * `type` decides which detail fields and which variant options are required:
 *   clothing -> fabric, fit_details + colour x size variants
 *   perfume  -> volume variants
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"CreateProduct") @guard @permission(require: ["product.create"])',
    inputs: [
        'type' => 'String! @rules(apply:["required","in:clothing,perfume"])',
        'name' => 'String! @rules(apply:["required","max:180"])',
        'description' => 'String @rules(apply:["nullable","max:5000","min:20"])',
        'fabric' => 'String @rules(apply:["nullable","max:500"])',
        'fit_details' => 'String @rules(apply:["nullable","max:500"])',
        'is_active' => 'Boolean! = true',
        'is_featured' => 'Boolean! = false',
        'category_ids' => '[ID!]',
        'variants' => '[VariantInput!]!',
    ],
    return_type: 'Product!',
)]
class CreateProduct
{
    public function __construct(private readonly ProductVariantService $variants) {}

    /** @param array<string, mixed> $args */
    public function __invoke($root, array $args): Product
    {
        $type = (string) $args['type'];
        $isPerfume = $type === Product::TYPE_PERFUME;

        // Whoever creates the product owns it, except an administrator listing
        // on behalf of the shop — those rows stay shop-owned (`seller_id` NULL)
        // so no seller can later claim them.
        $user = auth()->user();
        $sellerId = $user?->isSeller() ? $user->id : null;

        return DB::transaction(function () use ($args, $type, $isPerfume, $sellerId): Product {
            /** @var Product $product */
            $product = Product::create([
                'type' => $type,
                'seller_id' => $sellerId,
                'name' => trim((string) $args['name']),
                'description' => $this->text($args['description'] ?? null),
                // Perfume never stores clothing copy, and the other way round.
                'fabric' => $isPerfume ? null : $this->text($args['fabric'] ?? null),
                'fit_details' => $isPerfume ? null : $this->text($args['fit_details'] ?? null),
                'is_active' => (bool) ($args['is_active'] ?? true),
                'is_featured' => (bool) ($args['is_featured'] ?? false),
            ]);

            $this->variants->sync($product, $args['variants'] ?? []);
            $this->syncCategories($product, $args['category_ids'] ?? []);

            return $this->reload($product);
        });
    }

    /** Trim, and treat an empty string as "not set". */
    protected function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * Replace the product's categories. An empty list clears them.
     *
     * @param  array<int, string|int>  $categoryIds
     */
    protected function syncCategories(Product $product, array $categoryIds): void
    {
        $ids = $categoryIds === []
            ? []
            : Category::query()->whereKey($categoryIds)->pluck('id')->all();

        $product->categories()->sync($ids);
    }

    /** Reload with everything the admin table and the product page read. */
    protected function reload(Product $product): Product
    {
        return $product->fresh([
            'variants.color',
            'variants.size',
            'variants.volume',
            'variants.images',
            'categories',
            'brands',
            'reviews',
        ]);
    }
}
