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
            'query' => '@guard @permission @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission @paginate(defaultCount: 10)',
        ],
        'create' => [
            'query' => '@guard @permission @create',
        ],
        'update' => [
            'query' => '@guard @permission @update',
        ],
        'delete' => [
            'query' => '@guard @permission @delete',
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
