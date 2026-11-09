<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A colour in the shop wide master list.
 *
 * Managed once under Admin → Variant lists, then picked when building a
 * clothing product, so adding a colour in one place makes it available on every
 * product. `hex_code` is the swatch the storefront renders; it is exposed to the
 * API as `hex`.
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
        'hex' => 'String',
    ],
    type_override: [
        'sort_order' => 'Int!',
        'is_active' => 'Boolean!',
        'hex' => 'String',
    ],
)]
class Color extends Model
{
    protected $fillable = [
        'name',
        'hex_code',
        'sort_order',
        'is_active',
    ];

    /** `hex_code` is the storage column; `hex` is the API field. */
    protected $hidden = ['hex_code'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /** The swatch value, exposed as `hex` because that is what it is. */
    public function getHexAttribute(): ?string
    {
        return $this->hex_code;
    }
}
