<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A placed order.
 *
 * Orders are created through the PlaceOrder mutation only, so the generated
 * `createOrder` / `updateOrder` / `deleteOrder` fields are admin only.
 */
#[AttributesModel(
    input_override: [
        'user_id' => 'Int',
        'status' => 'String',
        'postal_code' => 'String',
        'total_amount' => 'Float',
        'subtotal' => 'Float',
        'shipping_amount' => 'Float',
    ],
    operations: [
        'query' => [
            // Only the shop side may look an order up by id: a seller has to go
            // through `ManageOrders` (scoped to their own line items) and a
            // customer through `MyOrders`, so one seller can never read another
            // seller's order or a stranger's delivery address.
            'query' => '@guard @permission(require: ["order.view-all"]) @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission(require: ["order.view-all"]) @paginate(defaultCount: 10)',
        ],
        'create' => [
            'query' => '@guard @permission(require: ["order.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["order.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["order.delete"]) @delete',
        ],
    ],
    type_override: [
        'total_amount' => 'Float!',
        'subtotal' => 'Float!',
        'shipping_amount' => 'Float!',
        'cod_fee' => 'Float!',
        // Optional: many Pakistani addresses have no postal code at all.
        'postal_code' => 'String',
        'items' => '[OrderItem!]! @hasMany',
        'user' => 'User @belongsTo',
        'status_label' => 'String!',
        'payment_method_label' => 'String!',
        'seller_ids' => '[ID!]!',
    ],
)]
class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ON_WAY = 'on-way';

    public const STATUS_RECEIVED = 'recieve';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ON_WAY, self::STATUS_RECEIVED];

    public const PAYMENT_COD = 'cod';

    public const PAYMENT_BANK = 'bank';

    public const PAYMENT_WALLET = 'wallet';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REFUNDED = 'refunded';

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'province',
        'city',
        'street',
        'postal_code',
        'notes',
        'subtotal',
        'shipping_amount',
        'cod_fee',
        'total_amount',
        'status',
        'payment_method',
        'payment_status',
        'tracking_number',
        'courier',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'shipping_amount' => 'float',
        'cod_fee' => 'float',
        'total_amount' => 'float',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The products of this order through the line items.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'order_product')
            ->withPivot('quantity', 'price');
    }

    public function getStatusLabelAttribute(): string
    {
        return (string) config('shop.order_statuses.'.$this->status, $this->status);
    }

    /**
     * Ids of the sellers whose products appear in this order.
     *
     * The admin order table uses it to show an owner column for
     * administrators, who see every order in the shop.
     *
     * @return array<int, int>
     */
    public function getSellerIdsAttribute(): array
    {
        return $this->items
            ->map(fn (OrderItem $item): ?int => $item->product?->seller_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return (string) config('shop.payment_methods.'.$this->payment_method, $this->payment_method);
    }
}
