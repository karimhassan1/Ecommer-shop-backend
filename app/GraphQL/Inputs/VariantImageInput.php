<?php declare(strict_types=1);

namespace App\GraphQL\Inputs;

use Nodesol\LaraQL\Attributes\Input;

/**
 * One photo of one variant, as submitted by the admin form.
 *
 * `url` is whatever the upload endpoint returned (a storage path or an absolute
 * URL). `is_primary` marks the main picture of that variant; when no image of a
 * variant is marked the backend promotes the first one.
 *
 * Omitting `id` uploads a new photo; sending one keeps the stored row, so the
 * gallery can be reordered or trimmed without recreating every image (and
 * without leaving the deleted files behind on disk). The order of the array is
 * the order of the gallery.
 */
#[Input(
    name: 'VariantImageInput',
    inputs: [
        'id' => 'ID',
        'url' => 'String!',
        'is_primary' => 'Boolean',
        'sort_order' => 'Int',
    ],
)]
class VariantImageInput
{
    public function __construct(
        public ?string $id = null,
        public string $url = '',
        public ?bool $is_primary = null,
        public ?int $sort_order = null,
    ) {}
}
