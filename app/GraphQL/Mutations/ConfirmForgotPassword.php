<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\PasswordResetCodeService;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Finish a password reset with the six digit code from the inbox.
 *
 * `code` is the OTP the customer received. `token` is accepted as an alias
 * because the SPA used to post a reset link token under that name, and an old
 * build in a browser tab must not fail with a validation error after deploying
 * this change.
 */
#[Type(
    columns: [
        'message' => 'String!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"ConfirmForgotPassword")',
    inputs: [
        'email' => 'String! @rules(apply:["required","email"])',
        'code' => 'String @rules(apply:["nullable","digits:6"])',
        'token' => 'String @rules(apply:["nullable"])',
        'password' => 'String! @rules(apply:["required","confirmed","min:8"])',
        'password_confirmation' => 'String! @rules(apply:["required"])',
    ]
)]
class ConfirmForgotPassword
{
    public function __construct(private readonly PasswordResetCodeService $codes) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $code = trim((string) ($args['code'] ?? $args['token'] ?? ''));

        $this->codes->reset(
            (string) $args['email'],
            $code,
            (string) $args['password'],
        );

        return ['message' => 'Password reset successfully. You can now sign in.'];
    }
}

