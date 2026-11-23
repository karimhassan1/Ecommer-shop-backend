<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A catalog category, split by product type so the storefront can offer a
 * clothing list and a perfume list.
 *
 * The slug is derived from the name on save and is what the shop filters by
 * (`/shop?category=pret-ready-to-wear`).
 */
#[AttributesModel(
    input_override: [
        'type' => 'String',
        'sort_order' => 'Int',
        'slug' => 'String',
    ],
    type_override: [
        'type' => 'String!',
        'sort_order' => 'Int!',
        // withCount() from AdminCategories / CategoryList.
        'product_count' => 'Int',
        'products' => '[Product!]! @belongsToMany',
    ],
)]
class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /** Products filed under this category. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_categories');
    }

    /**
     * How many products are filed under this category, as `product_count`.
     *
     * `AdminCategories` and `CategoryList` both `withCount('products')`, which
     * yields `products_count`; this exposes it under the name the admin screen
     * reads, defaulting to 0 when the count was not requested.
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
        static::saving(function (Category $category): void {
            if (! $category->slug) {
                $category->slug = static::uniqueSlug((string) $category->name, $category->getKey());
            }
        });
    }

    /**
     * Slug that no other category already uses.
     *
     * The column is unique, so a second "Pret" becomes "pret-2" instead of
     * failing the save.
     */
    protected static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
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
