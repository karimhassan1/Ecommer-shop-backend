<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * Message sent from the public contact form. Reading them is admin only.
 */
#[AttributesModel(
    operations: [
        'query' => [
            'query' => '@guard @permission(require: ["message.view"]) @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission(require: ["message.view"]) @paginate(defaultCount: 10)',
        ],
        'create' => [
            'query' => '@guard @permission(require: ["message.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["message.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["message.delete"]) @delete',
        ],
    ],
    type_override: [
        'is_read' => 'Boolean!',
    ],
)]
class ContactMessage extends Model
{
    protected $fillable = [
        'name',
        'email',
        'subject',
        'message',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];
}
