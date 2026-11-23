<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A maker / label a product can be filed under.
 *
 * The link is optional: a product may belong to a brand, or to none at all
 * (hand-made pieces, samples, gift sets). The storefront therefore never
 * requires one, and a product saved without it simply has an empty list.
 *
 * Writes go through the `SaveBrand` / `DeleteBrand` mutations, which is where
 * the slug and the per-action permission checks live.
 */
#[AttributesModel(
    operations: [
        'create' => [
            'query' => '@guard @permission(require: ["brand.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["brand.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["brand.delete"]) @delete',
        ],
    ],
    input_override: [
        'slug' => 'String',
    ],
    type_override: [
        'slug' => 'String!',
        // withCount() from AdminBrands / ShopFilters.
        'product_count' => 'Int',
        'products' => '[Product!]! @belongsToMany',
    ],
)]
class Brand extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    /** Products carrying this label. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_brands');
    }

    /**
     * How many products carry this brand, as `product_count`.
     *
     * The admin query and the storefront facet both `withCount('products')`,
     * which produces a `products_count` attribute. This maps it onto the field
     * the API exposes, and falls back to the loaded relation (then to zero)
     * when a caller did not ask for the count — so a brand with no products
     * reports 0 rather than `null`.
     */
    public function getProductCountAttribute(): int
    {
        if (array_key_exists('products_count', $this->attributes)) {
            return (int) $this->attributes['products_count'];
        }

        return $this->relationLoaded('products') ? $this->products->count() : 0;
    }

    protected static function booted(): void
    {
        static::saving(function (Brand $brand): void {
            if (! $brand->slug) {
                $brand->slug = static::uniqueSlug((string) $brand->name, $brand->getKey());
            }
        });
    }

    /**
     * Slug that no other brand already uses.
     *
     * The column is unique, so a second "Atelier" becomes "atelier-2" instead
     * of failing the save.
     */
    protected static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
