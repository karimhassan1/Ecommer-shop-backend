<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Hash;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns_override: [
        'message' => 'String!',
    ]
)]
#[Mutation(
    name: '',
    query: '@field(resolver:"ResetPassword") @guard',
    inputs: [
        'current_password' => 'String! @rules(apply:["required"])',
        'password' => 'String! @rules(apply:["required","confirmed","min:8"])',
        'password_confirmation' => 'String! @rules(apply:["required"])',
    ],
)]
class ResetPassword
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        if (! Hash::check((string) $args['current_password'], $user->password)) {
            throw new UserError('Your current password is incorrect.');
        }

        if (Hash::check((string) $args['password'], $user->password)) {
            throw new UserError('The new password must be different from the current one.');
        }

        $user->forceFill(['password' => (string) $args['password']])->save();

        // Invalidate every other session after a password change.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))->delete();

        return ['message' => 'Password updated successfully.'];
    }
}
