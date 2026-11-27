<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A fabric a clothing product is made of (Cotton, Lawn, Khaddar, …).
 *
 * Managed once under Admin → Fabric types, then picked when building a clothing
 * product — the same shape as `Brand`, so the panel reuses one editor pattern.
 *
 * Only clothing carries one: the storefront's fragrance page hides the facet
 * because a perfume has no fabric.
 *
 * Writes go through the `SaveFabricType` / `DeleteFabricType` mutations, which
 * is where the slug and the per-action permission checks live.
 */
#[AttributesModel(
    operations: [
        'create' => [
            'query' => '@guard @permission(require: ["product.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["product.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["product.delete"]) @delete',
        ],
    ],
    input_override: [
        'slug' => 'String',
        'sort_order' => 'Int',
        'is_active' => 'Boolean',
    ],
    type_override: [
        'slug' => 'String',
        'sort_order' => 'Int!',
        'is_active' => 'Boolean!',
        // withCount() from AdminFabricTypes / ShopFilters.
        'product_count' => 'Int',
        'products' => '[Product!]! @hasMany',
    ],
)]
class FabricType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** Clothing products made of this fabric. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * How many products use this fabric, as `product_count`.
     *
     * The admin query and the storefront facet both `withCount('products')`,
     * which produces a `products_count` attribute. This maps it onto the field
     * the API exposes, and falls back to the loaded relation (then to zero) when
     * a caller did not ask for the count.
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
        static::saving(function (FabricType $fabricType): void {
            if (! $fabricType->slug) {
                $fabricType->slug = static::uniqueSlug((string) $fabricType->name, $fabricType->getKey());
            }
        });
    }

    /**
     * Slug that no other fabric already uses.
     *
     * The column is unique, so a second "Cotton" becomes "cotton-2" instead of
     * failing the save.
     */
    protected static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'fabric';
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
