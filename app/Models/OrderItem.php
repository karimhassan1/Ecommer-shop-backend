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
        'price' => 'Float!',
        'line_total' => 'Float!',
        'order' => 'Order @belongsTo',
        'product' => 'Product @belongsTo',
        'variant' => 'ProductVariant @belongsTo',
        'variant_label' => 'String',
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
        'size_label',
        'color_name',
        'quantity',
        'price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'float',
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

    public function getLineTotalAttribute(): float
    {
        return (float) ($this->quantity * $this->price);
    }
}
