<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\VerificationCodeService;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Resend verification for the signed in customer.
 *
 * Verification is done with a six digit code (there is no signed link any
 * more), so this simply issues a fresh code. The code service owns the
 * cooldown, which is what stops a customer hammering the SMTP server.
 */
#[Type(
    columns: [
        'message' => 'String!',
        'email' => 'String!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"ResendVerificationEmail") @guard',
    inputs: [],
)]
class ResendVerificationEmail
{
    public function __construct(
        private readonly VerificationCodeService $codes,
    ) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            throw new UserError('Your email address is already verified.');
        }

        $sent = $this->codes->send($user);

        return [
            'message' => $sent
                ? 'A fresh 6 digit code has been sent to your email address.'
                : 'We could not send the verification email right now, please try again later.',
            'email' => (string) $user->email,
        ];
    }
}


