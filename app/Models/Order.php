<?php

namespace App\Models;

use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

#[AttributesModel(
    input_override: [
        'user_id' => 'Int',
        'status'=>'String',
        'postal_code'=>'String!',
        'total_amount'=>'Int'
    ],
    operations: [
        'create' => [
            'return_type'=>'Order! @inject(context:"user.id", name:"user_id")'
        ]
    ],
    directives: ['@guard']
)]

#[ObservedBy(OrderObserver::class)]
class Order extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'email',
        'province',
        'city',
        'street',
        'postal_code',
        'notes',
        'total_amount',
        'status'
    ];

    public function orderProudcts():BelongsToMany
    {
        return $this->belongsToMany(Product::class,'order_product')->withPivot('quantity','price');
    }

    public function user():BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}