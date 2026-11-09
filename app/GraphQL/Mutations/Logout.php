<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns_override: [
        'message' => 'String!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"Logout") @guard',
    inputs: [],
)]
class Logout
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = auth()->user();

        // Revoke only the token used for this request so other devices stay logged in.
        $currentToken = $user?->currentAccessToken();

        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        return ['message' => 'Logged out successfully.'];
    }
}
