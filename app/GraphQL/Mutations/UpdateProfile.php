<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\GeoService;
use App\Support\Address;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Update the signed in customer's own profile.
 *
 * `state` and `city` are validated against the Pakistani province list so a
 * profile cannot end up with a city that does not belong to its province; that
 * matters because checkout pre-fills the delivery form from these fields.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"UpdateProfile") @guard',
    inputs: [
        'name' => 'String @rules(apply:["max:120"])',
        'phone' => 'String @rules(apply:["max:30"])',
        'address' => 'String @rules(apply:["max:255"])',
        'city' => 'String @rules(apply:["max:120"])',
        'state' => 'String @rules(apply:["max:120"])',
        'zip' => 'String @rules(apply:["max:20"])',
        'avatar' => 'String @rules(apply:["max:2048"])',
    ],
    return_type: 'User!',
)]
class UpdateProfile
{
    public function __construct(private readonly GeoService $geo) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        // Province / city are validated against the live catalogue the profile
        // form was filled from, which is the third party list merged over the
        // shipped one.
        $this->geo->bootAddressCatalogue();

        // Province / city are validated together: a city is only meaningful
        // inside the province that contains it.
        $state = $this->canonicalState($args['state'] ?? $user->state);
        $city = $this->canonicalCity($state, $args['city'] ?? $user->city);

        $fillable = array_filter(
            [
                'name' => $args['name'] ?? null,
                'phone' => $args['phone'] ?? null,
                'address' => $args['address'] ?? null,
                'zip' => $args['zip'] ?? null,
                'avatar' => $args['avatar'] ?? null,
            ],
            static fn ($value): bool => $value !== null && $value !== '',
        );

        if (array_key_exists('state', $args) && $args['state'] !== null) {
            $fillable['state'] = $state;
        }

        if (array_key_exists('city', $args) && $args['city'] !== null) {
            $fillable['city'] = $city;
        }

        $user->fill($fillable);
        $user->save();

        return $user->fresh();
    }

    /** A province name from the list, or the value as saved before the list. */
    protected function canonicalState(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        return Address::canonicalProvince($state) ?? $state;
    }

    /**
     * A city that belongs to the province, or `null`.
     *
     * An unknown city is rejected rather than silently stored: the checkout
     * would refuse it later, and the customer would not know why.
     */
    protected function canonicalCity(?string $state, ?string $city): ?string
    {
        if ($city === null || trim($city) === '') {
            return null;
        }

        $province = Address::canonicalProvince($state);

        if ($province === null) {
            // No (valid) province yet: accept the city as typed.
            return $city;
        }

        $canonical = Address::canonicalCity($province, $city);

        if ($canonical === null) {
            throw new UserError('Please choose a city inside the selected province.');
        }

        return $canonical;
    }
}

