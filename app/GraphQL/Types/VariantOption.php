<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * One master value of a variant, e.g. {kind: "size", value: "M"}.
 *
 * Exposed on `ProductVariant.options` so the storefront can match a customer's
 * selection back to a variant without extra requests.
 */
#[Type(
    name: 'VariantOption',
    columns: [
        'kind' => 'String!',
        'slug' => 'String!',
        'value' => 'String!',
        'hex' => 'String',
        'id' => 'Int!',
    ],
)]
class VariantOption
{
    public function __construct(
        public string $kind = '',
        public string $slug = '',
        public string $value = '',
        public ?string $hex = null,
        public int $id = 0,
    ) {}
}
