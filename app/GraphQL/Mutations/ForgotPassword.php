<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\MailService;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns: [
        'message' => 'String!',
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
    public function __construct(private readonly MailService $mail) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $this->mail->sendPasswordResetLink((string) $args['email']);

        // Always the same answer so registered emails cannot be enumerated.
        return ['message' => 'If that email address exists in our system, a password reset link has been sent.'];
    }
}
