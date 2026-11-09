<?php

namespace App\Models;

use App\Observers\GuestCartItemObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Guest cart lines are internal bookkeeping and not exposed through the API.
 */
#[ObservedBy(GuestCartItemObserver::class)]
class GuestCartItem extends Model
{
    protected $fillable = [
        'guest_cart_id',
        'product_id',
        'quantity',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
