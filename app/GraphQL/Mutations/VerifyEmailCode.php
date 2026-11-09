<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\VerificationCodeService;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Finish email verification with the six digit code.
 *
 * Returns the refreshed user as well as a message, so the SPA can drop its
 * "verify your email" gate the moment the code is accepted.
 */
#[Type(
    columns: [
        'message' => 'String!',
        'verified' => 'Boolean!',
        'user' => 'User!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"VerifyEmailCode") @guard',
    inputs: [
        'code' => 'String! @rules(apply:["required","digits:6"])',
    ],
)]
class VerifyEmailCode
{
    public function __construct(private readonly VerificationCodeService $codes) {}

    /** @param array<string, mixed> $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        $message = $this->codes->verify($user, (string) $args['code']);

        $user = $user->fresh();

        return [
            'message' => $message,
            'verified' => $user->hasVerifiedEmail(),
            'user' => $user,
        ];
    }
}
