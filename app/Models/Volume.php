<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A perfume volume in the shop wide master list.
 *
 * `label` is what the customer sees ("30 ml"); `ml` is the numeric weight so the
 * list can be sorted and filtered properly.
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
        'ml' => 'Float',
        'sort_order' => 'Int',
        'is_active' => 'Boolean',
    ],
    type_override: [
        'ml' => 'Float!',
        'sort_order' => 'Int!',
        'is_active' => 'Boolean!',
    ],
)]
class Volume extends Model
{
    protected $fillable = [
        'label',
        'ml',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'ml' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
