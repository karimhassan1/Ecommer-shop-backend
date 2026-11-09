<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns_override: [
        'message' => 'String!',
        'user' => 'User',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"ToggleAdminRole") @guard @permission',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'is_admin' => 'Boolean! @rules(apply:["required"])',
    ],
)]
class ToggleAdminRole
{
    public function __invoke($root, array $args)
    {
        /** @var User|null $user */
        $user = User::find($args['id']);

        if (! $user) {
            throw new UserError('That user could not be found.');
        }

        // Never allow an admin to lock themselves out of the panel.
        if ($user->id === auth()->id() && ! (bool) $args['is_admin']) {
            throw new UserError('You cannot remove your own administrator access.');
        }

        $user->forceFill(['is_admin' => (bool) $args['is_admin']])->save();

        return [
            'message' => $args['is_admin']
                ? 'Administrator access granted.'
                : 'Administrator access revoked.',
            'user' => $user->fresh(),
        ];
    }
}
