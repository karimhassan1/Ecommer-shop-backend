<?php declare(strict_types=1);

namespace App\GraphQL\Support;

use App\Models\User;
use GraphQL\Error\UserError;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

/**
 * LaraQL places the `directives` of a `#[Query]`/`#[Mutation]` attribute on the
 * root type (OBJECT location), where field-level directives such as `@can`
 * are not allowed. Admin resolvers therefore assert the gate in PHP using this
 * trait, which keeps the authorization rule in exactly one place.
 */
trait AuthorizesAdmin
{
    protected function requireAdmin(): User
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            throw new UserError('You must be signed in to do that.');
        }

        if (! $user->isStaff()) {
            throw new AuthorizationException('You are not allowed to perform this action.');
        }

        return $user;
    }

    protected function requireAuth(): User
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            throw new UserError('You must be signed in to do that.');
        }

        return $user;
    }
}
