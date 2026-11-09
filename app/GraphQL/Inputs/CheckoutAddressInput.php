<?php declare(strict_types=1);

namespace App\GraphQL\Inputs;

use Nodesol\LaraQL\Attributes\Input;

/**
 * Shipping details captured on the checkout page.
 *
 * `province` and `city` are Pakistani provinces / cities; the storefront feeds
 * the allowed values from `config('shop.provinces')`.
 */
#[Input(
    name: 'CheckoutAddressInput',
    inputs: [
        'name' => 'String!',
        'phone' => 'String!',
        'email' => 'String!',
        'province' => 'String!',
        'city' => 'String!',
        'street' => 'String!',
        'postal_code' => 'String',
        'notes' => 'String',
        'payment_method' => 'String',
    ],
)]
class CheckoutAddressInput
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $email,
        public string $province,
        public string $city,
        public string $street,
        public ?string $postal_code = null,
        public ?string $notes = null,
        public ?string $payment_method = null,
    ) {}
}
