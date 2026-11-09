<?php

namespace App\Models;

use GraphQL\Error\UserError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A concrete buyable option of a product.
 *
 *   clothing -> one combination of colour + size
 *   perfume  -> one volume
 *
 * Each variant owns its price, optional discount window, stock and photo
 * gallery, which is exactly what the storefront swaps when the customer picks
 * that combination.
 */
#[AttributesModel(
    operations: [
        'query' => [
            'query' => '@guard @permission(require: ["product.view"]) @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission(require: ["product.view"]) @paginate(defaultCount: 50)',
        ],
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
        'price' => 'Float!',
        'discount_price' => 'Float',
        'stock' => 'Int',
        'is_active' => 'Boolean',
        'is_default' => 'Boolean',
    ],
    type_override: [
        'price' => 'Float!',
        'discount_price' => 'Float',
        'final_price' => 'Float!',
        'stock' => 'Int!',
        'is_active' => 'Boolean!',
        'is_default' => 'Boolean!',
        'is_discounted' => 'Boolean!',
        'discount_percent' => 'Int!',
        'in_stock' => 'Boolean!',
        'label' => 'String!',
        'product' => 'Product @belongsTo',
        'color' => 'Color @belongsTo',
        'size' => 'Size @belongsTo',
        'volume' => 'Volume @belongsTo',
        'images' => '[VariantImage!]! @hasMany',
        'image_urls' => '[String!]!',
        'primary_image' => 'String',
    ],
)]
class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'color_id',
        'size_id',
        'volume_id',
        'sku',
        'price',
        'discount_price',
        'discount_starts_at',
        'discount_ends_at',
        'stock',
        'is_default',
        'is_active',
    ];

    /**
     * `default_slot` is a generated column used for the one-default-per-product
     * unique index. It must never be written or exposed.
     */
    protected $hidden = ['default_slot'];

    protected $casts = [
        'price' => 'float',
        'discount_price' => 'float',
        'discount_starts_at' => 'datetime',
        'discount_ends_at' => 'datetime',
        'stock' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Master colour this variant represents (clothing). */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /** Master size this variant represents (clothing). */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /** Master volume this variant represents (perfume). */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /** Photos of this exact variant, primary first. */
    public function images(): HasMany
    {
        return $this->hasMany(VariantImage::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function getInStockAttribute(): bool
    {
        return $this->is_active && $this->stock > 0;
    }

    /**
     * Is the discount inside its date window right now?
     *
     * A discount with no window is always live; a window with only a start (or
     * only an end) is honoured on the side that was given.
     */
    public function discountIsLive(): bool
    {
        if ($this->discount_price === null || $this->discount_price >= $this->price) {
            return false;
        }

        $now = now();

        if ($this->discount_starts_at !== null && $now->lt($this->discount_starts_at)) {
            return false;
        }

        if ($this->discount_ends_at !== null && $now->gt($this->discount_ends_at)) {
            return false;
        }

        return true;
    }

    public function getIsDiscountedAttribute(): bool
    {
        return $this->discountIsLive();
    }

    /**
     * The price the customer actually pays.
     *
     * This is the single source of truth for money: the cart, the order and the
     * product page all read it, so a discount can never disagree between them.
     */
    public function getFinalPriceAttribute(): float
    {
        return $this->discountIsLive()
            ? (float) $this->discount_price
            : (float) $this->price;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (! $this->discountIsLive() || $this->price <= 0) {
            return 0;
        }

        return (int) round((1 - ($this->final_price / $this->price)) * 100);
    }

    /**
     * Human label, e.g. "Black / M" (clothing) or "30 ml" (perfume).
     */
    public function getLabelAttribute(): string
    {
        $parts = collect([
            $this->color?->name,
            $this->size?->name,
            $this->volume?->label,
        ])->filter();

        return $parts->implode(' / ');
    }

    /** Absolute URLs of this variant's photos, in gallery order. */
    public function getImageUrlsAttribute(): array
    {
        return $this->images
            ->map(fn (VariantImage $image): string => $image->url)
            ->all();
    }

    public function getPrimaryImageAttribute(): ?string
    {
        return $this->images->firstWhere('is_primary', true)?->url
            ?? $this->images->first()?->url;
    }

    /**
     * Has this variant ever appeared on an order?
     *
     * Used before deleting a variant: an ordered variant is deactivated instead
     * so historical order lines keep their link and their snapshot labels.
     */
    public function hasOrders(): bool
    {
        return $this->newQuery()
            ->whereKey($this->getKey())
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('order_product')
                    ->whereColumn('order_product.product_variant_id', 'product_variants.id');
            })
            ->exists();
    }

    /**
     * Keep the catalogue invariants even for writes that skip the service.
     *
     * `ProductVariantService` is the normal path and validates everything before
     * writing, but the generated `createProductVariant` / `updateProductVariant`
     * mutations write straight to the table. These hooks make the two rules that
     * would otherwise corrupt a product — a second default and a product losing
     * its last variant — impossible whichever way the row is saved.
     */
    protected static function booted(): void
    {
        // A seller may only touch variants of products they own. The generic
        // `createProductVariant` / `updateProductVariant` / `deleteProductVariant`
        // mutations write straight to the table, so the rule is asserted here
        // rather than only in the admin resolver.
        static::saving(function (ProductVariant $variant): void {
            $user = auth()->user();

            if (! $user?->isSeller() || ! $variant->product_id) {
                return;
            }

            $ownerId = \App\Models\Product::query()
                ->whereKey($variant->product_id)
                ->value('seller_id');

            if ($ownerId !== null && (int) $ownerId !== (int) $user->id) {
                throw new UserError('You can only edit variants of products you sell.');
            }
        });

        static::deleting(function (ProductVariant $variant): void {
            $user = auth()->user();

            if ($user?->isSeller() && $variant->product && ! $variant->product->isOwnedBy($user)) {
                throw new UserError('You can only edit variants of products you sell.');
            }
        });

        // One default per product: claiming the flag releases the old holder
        // first, so the unique index never sees two defaults at once.
        static::saving(function (ProductVariant $variant): void {
            if (! $variant->is_default) {
                return;
            }

            static::query()
                ->where('product_id', $variant->product_id)
                ->when($variant->exists, fn ($query) => $query->whereKeyNot($variant->getKey()))
                ->update(['is_default' => false]);
        });

        // A product always has at least one variant. The service clears the old
        // rows before inserting the replacements, so it opts out of this guard
        // with `withoutLastVariantGuard()` after checking the payload is not empty.
        static::deleting(function (ProductVariant $variant): void {
            if (static::$allowDeletingLastVariant) {
                return;
            }

            $remaining = static::query()
                ->where('product_id', $variant->product_id)
                ->whereKeyNot($variant->getKey())
                ->exists();

            if (! $remaining) {
                throw new UserError(
                    'A product needs at least one variant, so the last one cannot be deleted.'
                );
            }
        });
    }

    /** Set while the caller has already guaranteed the product keeps a variant. */
    protected static bool $allowDeletingLastVariant = false;

    /**
     * Run a callback that may remove every variant of a product.
     *
     * Only for callers that replace the tree in one go and have validated that
     * at least one variant remains afterwards — i.e. `ProductVariantService`.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutLastVariantGuard(callable $callback): mixed
    {
        $previous = static::$allowDeletingLastVariant;
        static::$allowDeletingLastVariant = true;

        try {
            return $callback();
        } finally {
            static::$allowDeletingLastVariant = $previous;
        }
    }
}
