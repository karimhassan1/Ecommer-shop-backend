<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * A Pakistani province together with the cities we deliver to.
 */
#[Type(
    name: 'Province',
    columns: [
        'name' => 'String!',
        'cities' => '[String!]!',
    ],
)]
class Province
{
    /**
     * @param  array<int, string>  $cities
     */
    public function __construct(
        public string $name,
        public array $cities = [],
    ) {}
}
