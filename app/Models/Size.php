<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A garment size in the shop wide master list (S, M, L, 38, 40 …).
 *
 * Managed under Admin → Variant lists and picked per colour when building a
 * clothing product.
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
        'sort_order' => 'Int',
        'is_active' => 'Boolean',
    ],
    type_override: [
        'sort_order' => 'Int!',
        'is_active' => 'Boolean!',
        'label' => 'String!',
    ],
)]
class Size extends Model
{
    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** "M" reads better as a label in the admin list and on order lines. */
    public function getLabelAttribute(): string
    {
        return (string) $this->name;
    }
}
