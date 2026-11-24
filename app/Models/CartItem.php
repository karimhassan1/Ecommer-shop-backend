<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A line of the authenticated user's cart.
 *
 * The cart is only ever read and written through AddToCart/UpdateCartItem/
 * RemoveCartItem, which scope every lookup to the owner of the cart. The auto
 * generated collection is admin only so carts cannot be enumerated.
 */
#[AttributesModel(
    operations: [
        'query' => [
            // Cart lines are private to their owner and only reachable through
            // MyCart, which pins the lookup to the signed in user.
            'query' => '@guard @permission(require: ["user.view"]) @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission(require: ["user.view"]) @paginate(defaultCount: 10)',
        ],
        'create' => [
            'query' => '@guard @permission(require: ["user.update"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["user.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["user.update"]) @delete',
        ],
    ],
    type_override: [
        'cart' => 'Cart @belongsTo',
        'product' => 'Product @belongsTo',
        'variant' => 'ProductVariant @belongsTo',
        'line_total' => 'Float!',
        'unit_price' => 'Float!',
        'label' => 'String',
    ],
)]
class CartItem extends Model
{
    /** @use HasFactory<\Database\Factories\CartItemFactory> */
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'product_variant_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * What one unit costs, including any live discount on the variant.
     */
    public function getUnitPriceAttribute(): float
    {
        return (float) ($this->variant?->final_price ?? 0);
    }

    public function getLabelAttribute(): ?string
    {
        return $this->variant?->label;
    }

    /**
     * Quantity multiplied by the unit price of the selected variant.
     */
    public function getLineTotalAttribute(): float
    {
        return (float) ($this->quantity * $this->unit_price);
    }
}
