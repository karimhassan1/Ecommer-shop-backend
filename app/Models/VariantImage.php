<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nodesol\LaraQL\Attributes\Model as AttributesModel;

/**
 * One photo of one variant.
 *
 * Images live only here: the products table has no image column, so the product
 * page gallery is always exactly the photos of the selected variant.
 *
 * `url` stores whatever the admin form produced — a path on the configured disk
 * ("products/9f3a.jpg") or an absolute URL. It is resolved through MediaService
 * so switching to S3 or a CDN stays a configuration change.
 */
#[AttributesModel(
    operations: [
        'query' => [
            'query' => '@guard @permission(require: ["product.view"]) @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission(require: ["product.view"]) @paginate(defaultCount: 50)',
        ],
        'create' => [
            'query' => '@guard @permission(require: ["product.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["product.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["product.delete"]) @delete',
        ],
    ],
    input_override: [
        'is_primary' => 'Boolean',
        'sort_order' => 'Int',
    ],
    type_override: [
        'is_primary' => 'Boolean!',
        'sort_order' => 'Int!',
        'variant' => 'ProductVariant @belongsTo',
    ],
)]
class VariantImage extends Model
{
    protected $fillable = [
        'product_variant_id',
        'url',
        'sort_order',
        'is_primary',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getUrlAttribute(?string $value): string
    {
        return app(MediaService::class)->url($value);
    }

    protected static function booted(): void
    {
        // Remove the file when the row is deleted so storage does not grow.
        // Works for both the local disk and Oracle / S3; `forget()` resolves a
        // resolved URL back to its object key and ignores foreign URLs.
        static::deleting(function (VariantImage $image): void {
            app(MediaService::class)->forget($image->getRawOriginal('url'));
        });

        // Replacing the photo on an existing row would otherwise leak the file
        // the row used to point at.
        static::updating(function (VariantImage $image): void {
            if (! $image->isDirty('url')) {
                return;
            }

            $previous = $image->getRawOriginal('url');
            $next = $image->getAttributes()['url'] ?? null;

            if ($previous !== null && $previous !== '' && $previous !== $next) {
                app(MediaService::class)->forget($previous);
            }
        });
    }
}
