<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * Key/value store for shop wide configuration managed from the admin panel.
 *
 * Values are read through App\Services\SettingService, which caches them, so
 * this model is not exposed as a generic CRUD surface.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];
}
