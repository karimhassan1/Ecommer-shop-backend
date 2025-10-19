<?php

namespace App\Models;

use App\Observers\GuestCartItemObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

#[AttributesModel()]

#[ObservedBy(GuestCartItemObserver::class)]
class GuestCartItem extends Model
{
    protected $fillable = 
    [
        'guest_cart_id',
        'product_id',
        'quantity'
    ];

    public function product():BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

}
