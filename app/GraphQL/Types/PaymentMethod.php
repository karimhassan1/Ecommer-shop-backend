<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * A payment option currently offered, with the instructions the customer needs
 * (bank account, wallet number) and any extra fee it carries.
 */
#[Type(
    name: 'PaymentMethod',
    columns: [
        'value' => 'String!',
        'label' => 'String!',
        'fee' => 'Float!',
        'details' => 'String',
    ],
)]
class PaymentMethod
{
    public function __construct(
        public string $value,
        public string $label,
        public float $fee = 0.0,
        public ?string $details = null,
    ) {}
}
