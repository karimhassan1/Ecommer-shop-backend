<?php

namespace App\Models;

use App\Observers\GuestCartObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

#[AttributesModel(
    input_override:[
        'token'=>'String'
    ]
)]
#[ObservedBy(GuestCartObserver::class)]
class GuestCart extends Model
{
    //
    protected $fillable =
    [
        'token'
    ];

    public function CartItem():HasMany
    {
        return $this->hasMany(GuestCartItem::class);
    }
}
