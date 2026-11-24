<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * One cart per user. Only ever resolved through the cart resolvers, which pin
 * the lookup to the authenticated user.
 */
#[AttributesModel(
    operations: [
        'query' => [
            // A cart belongs to exactly one customer and is only ever read
            // through MyCart, which pins the lookup to the signed in user. The
            // generated lookup would let anybody read a stranger's basket by id,
            // so it is reserved for the shop side.
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
            'query' => '@guard @permission(require: ["user.delete"]) @delete',
        ],
    ],
    type_override: [
        'cartItem' => '[CartItem!]! @hasMany',
        'user' => 'User @belongsTo',
        'total_amount' => 'Float!',
        'items_count' => 'Int!',
    ],
)]
class Cart extends Model
{
    /** @use HasFactory<\Database\Factories\CartFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
    ];

    public function cartItem(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Grand total of every line in the cart, using the final price of the
     * chosen variant (discount included).
     */
    public function getTotalAmountAttribute(): float
    {
        return (float) $this->cartItem->sum(
            fn (CartItem $item): float => $item->quantity * $item->unit_price
        );
    }

    public function getItemsCountAttribute(): int
    {
        return (int) $this->cartItem->sum('quantity');
    }
}
