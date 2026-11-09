<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductVariantService;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: update a catalog product.
 *
 * Every input except `id` is optional so the panel can patch one field (for
 * example toggling `is_active`). `variants`, when present, is a full
 * description of the variant tree: rows are matched by id, new rows inserted
 * and missing rows removed (or deactivated when an order already points at
 * them). If the removed tree no longer contains the current default, the
 * request must nominate a new one.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"UpdateProduct") @guard @permission(require: ["product.update"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'type' => 'String @rules(apply:["nullable","in:clothing,perfume"])',
        'name' => 'String @rules(apply:["nullable","max:180"])',
        'description' => 'String @rules(apply:["nullable","max:5000","min:20"])',
        'fabric' => 'String @rules(apply:["nullable","max:500"])',
        'fit_details' => 'String @rules(apply:["nullable","max:500"])',
        'is_active' => 'Boolean',
        'is_featured' => 'Boolean',
        'category_ids' => '[ID!]',
        'variants' => '[VariantInput!]',
    ],
    return_type: 'Product!',
)]
class UpdateProduct
{
    public function __construct(private readonly ProductVariantService $variants) {}

    /** @param array<string, mixed> $args */
    public function __invoke($root, array $args): Product
    {
        /** @var Product|null $product */
        $product = Product::find($args['id']);

        if (! $product) {
            throw new UserError('That product could not be found.');
        }

        // The permission check already happened in the directive; this is the
        // row level half of it. A seller may only edit products they own.
        if (! auth()->user()?->can('update', $product)) {
            throw new UserError('You can only edit products you sell.');
        }

        return DB::transaction(function () use ($product, $args): Product {
            $this->updateProduct($product, $args);

            if (array_key_exists('variants', $args) && $args['variants'] !== null) {
                $this->guardDefault($product, $args['variants']);

                $this->variants->sync($product, $args['variants']);
            }

            if (array_key_exists('category_ids', $args) && $args['category_ids'] !== null) {
                $product->categories()->sync(
                    Category::query()->whereKey($args['category_ids'])->pluck('id')->all()
                );
            }

            return $product->fresh([
                'variants.color',
                'variants.size',
                'variants.volume',
                'variants.images',
                'categories',
                'brands',
                'reviews',
            ]);
        });
    }

    /**
     * Apply only the fields that were actually sent.
     *
     * @param  array<string, mixed>  $args
     */
    protected function updateProduct(Product $product, array $args): void
    {
        $payload = [];

        foreach (['type', 'name', 'description', 'fabric', 'fit_details'] as $field) {
            if (! array_key_exists($field, $args) || $args[$field] === null) {
                continue;
            }

            $value = trim((string) $args[$field]);
            $payload[$field] = $value === '' ? null : $value;
        }

        if (isset($payload['type'])) {
            // Switching type invalidates the other type's detail block.
            if ($payload['type'] === Product::TYPE_PERFUME) {
                $payload['fabric'] = null;
                $payload['fit_details'] = null;
            }
        }

        if (array_key_exists('is_active', $args) && $args['is_active'] !== null) {
            $payload['is_active'] = (bool) $args['is_active'];
        }

        if (array_key_exists('is_featured', $args) && $args['is_featured'] !== null) {
            $payload['is_featured'] = (bool) $args['is_featured'];
        }

        if ($payload !== []) {
            $product->update($payload);
        }
    }

    /**
     * Removing the current default requires nominating a new one.
     *
     * The check compares the submitted variant ids against the stored default,
     * so an admin who deletes the default row without picking a replacement is
     * stopped instead of silently getting the first variant.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    protected function guardDefault(Product $product, array $variants): void
    {
        $default = $product->variants()->where('is_default', true)->first();

        if (! $default) {
            return;
        }

        $submittedIds = [];

        foreach ($variants as $variant) {
            if (! empty($variant['id'])) {
                $submittedIds[] = (int) $variant['id'];
            }
        }

        if (in_array((int) $default->id, $submittedIds, true)) {
            return;
        }

        $newDefault = collect($variants)->contains(
            static fn (array $variant): bool => (bool) ($variant['is_default'] ?? false)
        );

        if (! $newDefault) {
            throw new UserError(
                'You removed the default variant. Choose another variant as the default in the same save.'
            );
        }
    }
}
