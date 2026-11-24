<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Services\GeoService;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Every Pakistani province with the cities that belong to it.
 *
 * `ShopSettings.provinces` already carries the same list, but the checkout and
 * the profile form only need the geography — asking for the whole settings
 * payload (prices, payment options, media config) just to fill two selects is
 * wasteful, and it made the address form depend on the settings request
 * succeeding.
 *
 * Public on purpose: a guest checks out with an address before they have an
 * account.
 */
#[Query(
    name: 'PakistanLocations',
    query: '@field(resolver:"PakistanLocations")',
    filters: [
        'province: String',
    ],
    return_type: '[Province!]!',
)]
class PakistanLocations
{
    public function __construct(private readonly GeoService $geo) {}

    /**
     * @param  array{province?: string|null}  $args
     * @return array<int, array{name: string, cities: array<int, string>}>
     */
    public function __invoke($root, array $args): array
    {
        $provinces = $this->geo->provinceOptions();
        $wanted = strtolower(trim((string) ($args['province'] ?? '')));

        if ($wanted === '') {
            return $provinces;
        }

        // Asking for one province keeps the payload small when the checkout
        // only needs the cities of the province the customer already picked.
        return array_values(array_filter(
            $provinces,
            static fn (array $province): bool => strtolower($province['name']) === $wanted
        ));
    }
}
