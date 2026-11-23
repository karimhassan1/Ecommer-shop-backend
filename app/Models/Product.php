<?php

namespace App\Models;

use GraphQL\Error\UserError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * Catalog product.
 *
 * Reads are public so the storefront works for guests; every write is
 * restricted to administrators holding the matching permission.
 *
 * A product carries **no price and no image** — it only describes what the item
 * is. Everything a customer can buy lives on a variant:
 *
 *   clothing -> one variant per colour x size
 *   perfume  -> one variant per volume
 *
 * The product page therefore opens on `default_variant` and swaps the gallery
 * and price when the customer picks another combination.
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
        'type' => 'String',
        'audience' => 'String',
        'is_active' => 'Boolean',
        'is_featured' => 'Boolean',
        'rating_average' => 'Float',
        'rating_count' => 'Int',
        'seller_id' => 'ID',
    ],
    type_override: [
        'type' => 'String!',
        'audience' => 'String!',
        'is_active' => 'Boolean!',
        'is_featured' => 'Boolean!',
        'rating_average' => 'Float!',
        'rating_count' => 'Int!',
        'seller_id' => 'ID',
        'seller' => 'User @belongsTo',
        'seller_name' => 'String',
        'is_mine' => 'Boolean!',
        'brands' => '[Brand!]! @belongsToMany',
        'categories' => '[Category!]! @belongsToMany',
        'reviews' => '[Review!]! @hasMany',
        'variants' => '[ProductVariant!]! @hasMany',
        'default_variant' => 'ProductVariant',
        'default_variant_id' => 'ID',
        'colors' => '[ProductColorGroup!]!',
        'volumes' => '[ProductVolumeOption!]!',
        'in_stock' => 'Boolean!',
        'total_stock' => 'Int!',
        'primary_image' => 'String',
        'lowest_price' => 'Float!',
        'discount_percent' => 'Int!',
        'sizes' => '[String!]!',
    ],
)]
class Product extends Model
{
    public const TYPE_CLOTHING = 'clothing';

    public const TYPE_PERFUME = 'perfume';

    /**
     * The product types the shop sells, in the order the storefront lists them.
     */
    public const TYPES = [self::TYPE_CLOTHING, self::TYPE_PERFUME];

    /**
     * Who a product is merchandised for.
     *
     * The storefront filters on this the same way it filters on `type`, so a
     * jacket can sit under "Women's" without being duplicated.
     */
    public const AUDIENCE_MEN = 'men';

    public const AUDIENCE_WOMEN = 'women';

    public const AUDIENCE_UNISEX = 'unisex';

    public const AUDIENCES = [self::AUDIENCE_MEN, self::AUDIENCE_WOMEN, self::AUDIENCE_UNISEX];

    protected $fillable = [
        'type',
        'audience',
        'seller_id',
        'name',
        'description',
        'fabric',
        'fit_details',
        'is_active',
        'is_featured',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'rating_average' => 'float',
        'rating_count' => 'integer',
    ];

    /**
     * The seller account that owns this product.
     *
     * NULL means the shop itself owns it (created by an administrator), which
     * is what `ownedBy()` treats as "not sellable by a seller".
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** True when `$user` is the seller who listed this product. */
    public function isOwnedBy(?User $user): bool
    {
        if (! $user || $this->seller_id === null) {
            return false;
        }

        return (int) $this->seller_id === (int) $user->id;
    }

    /** Display name of the seller, for the admin product table. */
    public function getSellerNameAttribute(): ?string
    {
        return $this->seller?->name;
    }

    /** Whether the product belongs to the signed in user, for the panel. */
    public function getIsMineAttribute(): bool
    {
        return $this->isOwnedBy(auth()->user());
    }

    /**
     * Narrow a product query down to what the user may administrate.
     *
     * Sellers only ever see their own rows; administrators and other staff see
     * everything, so this scope is a no-op for them.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && $user->isSeller()) {
            return $query->where('seller_id', $user->id);
        }

        return $query;
    }

    /**
     * Attach a new row to its owner, and keep sellers inside their own shelf.
     *
     * LaraQL also generates a generic `createProduct` / `updateProduct` pair
     * from the table itself. Those write straight to the model, so the two
     * ownership rules live here as well:
     *
     *   creating — a seller's product is stamped with their id, never NULL
     *   saving   — a seller can never write to somebody else's product
     */
    protected static function booted(): void
    {
        // A blank or unknown audience would fall out of every storefront filter
        // (including "Unisex"), so it is normalised on the way in.
        static::saving(function (Product $product): void {
            $audience = strtolower(trim((string) $product->audience));

            $product->audience = in_array($audience, self::AUDIENCES, true)
                ? $audience
                : self::AUDIENCE_UNISEX;
        });

        static::creating(function (Product $product): void {
            $user = auth()->user();

            if ($user?->isSeller() && $product->seller_id === null) {
                $product->seller_id = $user->id;
            }
        });

        static::saving(function (Product $product): void {
            $user = auth()->user();

            if (! $user?->isSeller() || ! $product->exists) {
                return;
            }

            $ownerId = $product->getOriginal('seller_id');

            if ($ownerId !== null && (int) $ownerId !== (int) $user->id) {
                throw new UserError('You can only edit products you sell.');
            }
        });

        // A product that has been ordered keeps its rows for order history.
        static::deleting(function (Product $product): void {
            $user = auth()->user();

            if ($user?->isSeller() && ! $product->isOwnedBy($user)) {
                throw new UserError('You can only delete products you sell.');
            }
        });
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'product_brands');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Every buyable option, in creation order. */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    public function isPerfume(): bool
    {
        return $this->type === self::TYPE_PERFUME;
    }

    /**
     * The combination shown when the product page opens.
     *
     * Falls back to the first in-stock variant and then to the first variant at
     * all, so a product always has something to display.
     */
    public function getDefaultVariantAttribute(): ?ProductVariant
    {
        $variants = $this->variants;

        if ($variants->isEmpty()) {
            return null;
        }

        return $variants->firstWhere('is_default', true)
            ?? $variants->firstWhere('in_stock', true)
            ?? $variants->first();
    }

    public function getDefaultVariantIdAttribute(): ?int
    {
        $id = $this->default_variant?->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * Clothing: colours with the sizes each one comes in.
     *
     * Out-of-stock sizes stay in the list so the storefront can disable them
     * rather than hide them. Each entry carries the `variant_id` to select.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getColorsAttribute(): array
    {
        $groups = [];

        foreach ($this->variants as $variant) {
            $color = $variant->color;

            if (! $color) {
                continue;
            }

            $groups[$color->id] ??= [
                'id' => (int) $color->id,
                'name' => (string) $color->name,
                'hex' => $color->hex,
                'sizes' => [],
            ];

            $groups[$color->id]['sizes'][] = [
                'variant_id' => (int) $variant->id,
                'size_id' => $variant->size_id === null ? null : (int) $variant->size_id,
                'size' => (string) ($variant->size?->name ?? ''),
                'sort_order' => (int) ($variant->size?->sort_order ?? 0),
                'in_stock' => $variant->in_stock,
            ];
        }

        $groups = array_values($groups);

        foreach ($groups as &$group) {
            usort(
                $group['sizes'],
                static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order'],
            );
        }

        return $groups;
    }

    /**
     * Perfume: the volumes this product is sold in, lightest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVolumesAttribute(): array
    {
        return $this->variants
            ->filter(fn (ProductVariant $variant): bool => $variant->volume !== null)
            ->sortBy(fn (ProductVariant $variant): float => (float) $variant->volume->ml)
            ->map(fn (ProductVariant $variant): array => [
                'variant_id' => (int) $variant->id,
                'volume_id' => (int) $variant->volume_id,
                'label' => (string) $variant->volume->label,
                'ml' => (float) $variant->volume->ml,
                'in_stock' => $variant->in_stock,
            ])
            ->values()
            ->all();
    }

    /** A product is in stock when at least one of its variants is. */
    public function getInStockAttribute(): bool
    {
        return $this->variants->contains(fn (ProductVariant $variant): bool => $variant->in_stock);
    }

    public function getTotalStockAttribute(): int
    {
        return (int) $this->variants->sum('stock');
    }

    /** Cheapest final price across the variants, for "from Rs x". */
    public function getLowestPriceAttribute(): float
    {
        return (float) $this->variants->min(
            fn (ProductVariant $variant): float => $variant->final_price
        );
    }

    /** Best discount across the variants, as a percentage off. */
    public function getDiscountPercentAttribute(): int
    {
        return (int) $this->variants->max(
            fn (ProductVariant $variant): int => $variant->discount_percent
        );
    }

    /**
     * Main picture of the default variant, falling back to any variant photo.
     */
    public function getPrimaryImageAttribute(): ?string
    {
        return $this->default_variant?->primary_image
            ?: $this->variants
                ->map(fn (ProductVariant $variant): ?string => $variant->primary_image)
                ->filter()
                ->first();
    }

    /**
     * Distinct size (or volume) labels, for the short list on a product card.
     *
     * @return array<int, string>
     */
    public function getSizesAttribute(): array
    {
        return $this->variants
            ->map(fn (ProductVariant $variant): ?string => $variant->size?->name ?? $variant->volume?->label)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
