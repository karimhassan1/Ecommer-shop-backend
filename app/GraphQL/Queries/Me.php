<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use Nodesol\LaraQL\Attributes\Query;

/**
 * The currently authenticated user together with the role flag the SPA needs
 * to decide whether to show the admin panel.
 */
#[Query(
    name: 'me',
    query: '@field(resolver:"Me") @guard',
    filters: [],
    return_type: 'User!',
)]
class Me
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        return auth()->user();
    }
}
