<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\VerificationCodeService;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Send a fresh six digit verification code to the signed in customer.
 *
 * The code is what allows the customer to verify from the page they are on,
 * without being bounced through their mailbox and back.
 */
#[Type(
    columns: [
        'message' => 'String!',
        'email' => 'String!',
        'expires_in_minutes' => 'Int!',
        'sent' => 'Boolean!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SendEmailVerificationCode") @guard',
    inputs: [],
)]
class SendEmailVerificationCode
{
    public function __construct(private readonly VerificationCodeService $codes) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        $sent = $this->codes->send($user);

        return [
            'message' => $sent
                ? 'We sent a 6 digit code to '.$user->email.'.'
                : 'We could not send the email right now. Please try again shortly.',
            'email' => (string) $user->email,
            'expires_in_minutes' => VerificationCodeService::CODE_TTL_MINUTES,
            'sent' => $sent,
        ];
    }
}
