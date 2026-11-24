<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * One line of an order. Backed by the `order_product` pivot table, but exposed
 * as a first class type so quantity and price survive in the API.
 */
#[AttributesModel(
    operations: [
        'query' => [
            // Order lines are the most sensitive rows in the shop: they join a
            // customer to an address and a price. Reading one by id is therefore
            // reserved for the shop side; sellers see their own lines through
            // ManageOrders, customers through MyOrders.
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
        'price' => 'Float!',
        'list_price' => 'Float',
        'discount_amount' => 'Float',
        'line_total' => 'Float!',
        'order' => 'Order @belongsTo',
        'product' => 'Product @belongsTo',
        'variant' => 'ProductVariant @belongsTo',
        'variant_label' => 'String',
        'display_name' => 'String!',
        'savings' => 'Float!',
    ],
)]
class OrderItem extends Model
{
    protected $table = 'order_product';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        // Snapshots taken at checkout: the name, type and SKU of the product as
        // it was sold, so a later rename or deletion never rewrites history.
        'product_name',
        'product_type',
        'sku',
        'color_name',
        'size_label',
        'quantity',
        'price',
        'list_price',
        'discount_amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'float',
        'list_price' => 'float',
        'discount_amount' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * Size/colour snapshot, so old orders stay readable after a variant edit.
     */
    public function getVariantLabelAttribute(): ?string
    {
        $label = collect([$this->color_name, $this->size_label])
            ->filter()
            ->implode(' / ');

        return $label !== '' ? $label : null;
    }

    /**
     * What to call this line on an order, an invoice or a packing list.
     *
     * The stored snapshot wins: it is what the product was called when it was
     * sold. Only rows written before the snapshot columns existed fall back to
     * the live product, which keeps very old orders readable.
     */
    public function getDisplayNameAttribute(): string
    {
        $name = trim((string) ($this->product_name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return (string) ($this->product?->name ?? 'Product #'.$this->product_id);
    }

    /** The regular price this line was sold at (before any discount). */
    public function getListPriceValueAttribute(): float
    {
        return (float) ($this->list_price ?? $this->price);
    }

    /** Money taken off per unit at checkout. */
    public function getDiscountValueAttribute(): float
    {
        if ($this->discount_amount !== null) {
            return (float) $this->discount_amount;
        }

        return max(0.0, $this->list_price_value - (float) $this->price);
    }

    /** Total money the customer saved on this line. */
    public function getSavingsAttribute(): float
    {
        return $this->discount_value * (int) $this->quantity;
    }

    public function getLineTotalAttribute(): float
    {
        return (float) ($this->quantity * $this->price);
    }
}
