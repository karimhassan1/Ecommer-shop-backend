<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\PasswordResetCodeService;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Ask for a password reset code.
 *
 * The customer receives six digits (never a link) and types them on the reset
 * screen together with the new password. The answer is identical whether or not
 * the address exists, so this cannot be used to discover who has an account.
 */
#[Type(
    columns: [
        'message' => 'String!',
        'expires_in_minutes' => 'Int!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"ForgotPassword")',
    inputs: [
        'email' => 'String! @rules(apply:["required","email"])',
    ]
)]
class ForgotPassword
{
    public function __construct(private readonly PasswordResetCodeService $codes) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        try {
            $this->codes->send((string) $args['email']);
        } catch (UserError $error) {
            // The resend cooldown is the only failure worth surfacing: it tells
            // a legitimate customer to wait instead of hammering the form.
            // Everything else stays silent so the answer cannot leak whether an
            // address is registered.
            if (str_contains($error->getMessage(), 'Please wait')) {
                throw $error;
            }
        }

        return [
            'message' => 'If that email address exists in our system, a 6 digit reset code has been sent.',
            'expires_in_minutes' => PasswordResetCodeService::CODE_TTL_MINUTES,
        ];
    }
}

