<?php

namespace App\Models;

use App\Observers\ReviewObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * A customer review of a product.
 *
 * Reviews are written through the SubmitReview mutation, which enforces that
 * the customer actually bought the product. The generated collection is admin
 * only because it exposes every customer's review regardless of moderation.
 */
#[AttributesModel(
    operations: [
        'query' => [
            'query' => '@guard @permission(require: ["review.view"]) @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission(require: ["review.view"]) @paginate(defaultCount: 10)',
        ],
        'create' => [
            'query' => '@guard @permission(require: ["review.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["review.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["review.delete"]) @delete',
        ],
    ],
    type_override: [
        'rating' => 'Int!',
        'is_approved' => 'Boolean!',
        'product' => 'Product @belongsTo',
        'user' => 'User @belongsTo',
        'author_name' => 'String!',
    ],
)]
#[ObservedBy(ReviewObserver::class)]
class Review extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'rating',
        'title',
        'body',
        'is_approved',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_approved' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Display name kept separate so the storefront never has to expose the
     * reviewer's email address.
     */
    public function getAuthorNameAttribute(): string
    {
        return (string) ($this->user?->name ?: 'Customer');
    }
}
