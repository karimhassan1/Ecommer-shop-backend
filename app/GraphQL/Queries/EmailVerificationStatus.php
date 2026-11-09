<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Services\VerificationCodeService;
use Nodesol\LaraQL\Attributes\Query;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Everything the verification screen needs about the signed in account.
 *
 * The SPA shows a blocking screen for unverified accounts, so it needs to know
 * not only *whether* the address is verified but also how long the customer has
 * to wait before another code can be requested.
 */
#[Type(
    columns: [
        'email' => 'String!',
        'is_verified' => 'Boolean!',
        'code_ttl_minutes' => 'Int!',
        'resend_cooldown_seconds' => 'Int!',
        'seconds_until_resend' => 'Int!',
    ]
)]
#[Query(
    name: 'EmailVerificationStatus',
    query: '@field(resolver:"EmailVerificationStatus") @guard',
    filters: [],
)]
class EmailVerificationStatus
{
    public function __construct(private readonly VerificationCodeService $codes) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        return [
            'email' => (string) $user->email,
            'is_verified' => $user->hasVerifiedEmail(),
            'code_ttl_minutes' => VerificationCodeService::CODE_TTL_MINUTES,
            'resend_cooldown_seconds' => VerificationCodeService::RESEND_COOLDOWN_SECONDS,
            'seconds_until_resend' => $this->codes->cooldownRemaining($user),
        ];
    }
}
