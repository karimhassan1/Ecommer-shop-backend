<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

#[AttributesModel(
    operations: [
        'create' => [
            'query' => '@guard @permission(require: ["brand.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["brand.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["brand.delete"]) @delete',
        ],
    ],
    type_override: [
        'products' => '[Product!]! @belongsToMany',
    ],
)]
class Brand extends Model
{
    protected $fillable = [
        'name',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_brands');
    }
}
