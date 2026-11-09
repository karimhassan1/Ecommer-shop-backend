<?php

namespace App\Models;

use App\Observers\GuestCartObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * Guest carts live in the browser; they are internal bookkeeping and are not
 * exposed through the API at all.
 */
#[ObservedBy(GuestCartObserver::class)]
class GuestCart extends Model
{
    protected $fillable = [
        'token',
    ];

    public function cartItem(): HasMany
    {
        return $this->hasMany(GuestCartItem::class);
    }
}
