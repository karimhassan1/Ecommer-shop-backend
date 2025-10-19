<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

#[AttributesModel()]
class Brand extends Model
{
    protected $fillable = [
        'name'
    ];

}
