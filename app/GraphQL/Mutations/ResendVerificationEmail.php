<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\MailService;
use App\Services\VerificationCodeService;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Resend verification for the signed in customer.
 *
 * Both channels are refreshed at once — the signed link and the six digit
 * code — because the customer may come back from either the link in their
 * inbox or the code box on the verification screen.
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
        private readonly MailService $mail,
        private readonly VerificationCodeService $codes,
    ) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            throw new UserError('Your email address is already verified.');
        }

        // The code service owns the cooldown, so the two mails always travel
        // together and a customer cannot hammer the SMTP server.
        $sent = $this->codes->send($user);
        $this->mail->sendEmailVerification($user);

        return [
            'message' => $sent
                ? 'A fresh 6 digit code and verification link have been sent to your email address.'
                : 'We could not send the verification email right now, please try again later.',
            'email' => (string) $user->email,
        ];
    }
}

