<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

#[AttributesModel(
    directives:['@guard']
)]
class Cart extends Model
{
    /** @use HasFactory<\Database\Factories\CartFactory> */
    use HasFactory;

    protected $fillable =
    [
        'user_id'
    ];

    public function cartItem():HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
