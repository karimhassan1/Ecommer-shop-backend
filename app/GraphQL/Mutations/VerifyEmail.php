<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns_override: [
        'message' => 'String!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"VerifyEmail")',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'hash' => 'String! @rules(apply:["required"])',
    ]
)]
class VerifyEmail
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = User::find($args['id']);

        if (! $user) {
            throw new UserError('This verification link is not valid.');
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $args['hash'])) {
            throw new UserError('This verification link is not valid.');
        }

        if ($user->hasVerifiedEmail()) {
            return ['message' => 'Your email address is already verified.'];
        }

        $user->markEmailAsVerified();

        return ['message' => 'Email verified successfully. Thank you!'];
    }
}
