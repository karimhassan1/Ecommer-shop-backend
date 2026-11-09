<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Nodesol\LaraQL\Attributes\Mutation;

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
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        $user->fill(array_filter(
            $args,
            static fn ($value, $key): bool => $value !== null && $value !== '' && in_array($key, [
                'name', 'phone', 'address', 'city', 'state', 'zip', 'avatar',
            ], true),
            ARRAY_FILTER_USE_BOTH
        ));

        $user->save();

        return $user->fresh();
    }
}
