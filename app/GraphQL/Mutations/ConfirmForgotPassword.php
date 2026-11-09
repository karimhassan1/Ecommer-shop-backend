<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Password;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns_override: [
        'message' => 'String!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"ConfirmForgotPassword")',
    inputs: [
        'email' => 'String! @rules(apply:["required","email"])',
        'token' => 'String! @rules(apply:["required"])',
        'password' => 'String! @rules(apply:["required","confirmed","min:8"])',
        'password_confirmation' => 'String! @rules(apply:["required"])',
    ]
)]
class ConfirmForgotPassword
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $status = Password::reset(
            [
                'email' => (string) $args['email'],
                'password' => (string) $args['password'],
                'password_confirmation' => (string) $args['password_confirmation'],
                'token' => (string) $args['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();

                // A password reset must terminate every existing session.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new UserError(match ($status) {
                Password::INVALID_TOKEN => 'This password reset link is invalid or has expired.',
                Password::INVALID_USER => 'We could not find a user with that email address.',
                default => 'The password could not be reset, please request a new link.',
            });
        }

        return ['message' => 'Password reset successfully. You can now sign in.'];
    }
}
