<?php declare(strict_types=1);

namespace App\GraphQL\Inputs;

use Nodesol\LaraQL\Attributes\Input;

/**
 * One buyable combination of a product, as submitted by the admin form.
 *
 * Clothing: `color_id` + `size_id`.
 * Perfume:  `volume_id`.
 *
 * Omitting `id` inserts a new variant; sending one updates the stored row in
 * place so existing cart lines keep pointing at it. Every variant carries its
 * own price, optional discount, stock and photos.
 */
#[Input(
    name: 'VariantInput',
    inputs: [
        'id' => 'ID',
        'color_id' => 'ID',
        'size_id' => 'ID',
        'volume_id' => 'ID',
        'sku' => 'String @rules(apply:["nullable","max:80"])',
        'price' => 'Float! @rules(apply:["required","numeric","min:0.01"])',
        'discount_price' => 'Float @rules(apply:["nullable","numeric","min:0"])',
        'discount_starts_at' => 'DateTime',
        'discount_ends_at' => 'DateTime',
        'stock' => 'Int! = 0 @rules(apply:["integer","min:0"])',
        'is_default' => 'Boolean! = false',
        'is_active' => 'Boolean! = true',
        'images' => '[VariantImageInput!]!',
    ],
)]
class VariantInput
{
    /**
     * @param  array<int, array<string, mixed>|VariantImageInput>  $images
     */
    public function __construct(
        public ?string $id = null,
        public ?int $color_id = null,
        public ?int $size_id = null,
        public ?int $volume_id = null,
        public ?string $sku = null,
        public float $price = 0.0,
        public ?float $discount_price = null,
        public ?string $discount_starts_at = null,
        public ?string $discount_ends_at = null,
        public int $stock = 0,
        public bool $is_default = false,
        public bool $is_active = true,
        public array $images = [],
    ) {}
}
