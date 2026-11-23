<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns_override: [
        'token' => 'String!',
        'user' => 'User!',
        'message' => 'String!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"Login")',
    inputs: [
        'email' => 'String! @rules(apply:["required","email"])',
        'password' => 'String! @rules(apply:["required"])'
    ]
)]
class Login
{
    /**
     * Throttle key, one bucket per email + IP pair.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __invoke($root, array $args)
    {
        $email = Str::lower(trim((string) $args['email']));
        $throttleKey = $this->throttleKey($email);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw new UserError("Too many login attempts. Please try again in {$seconds} seconds.");
        }

        /** @var User|null $user */
        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if (! $user || ! Hash::check((string) $args['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw new UserError('Invalid email or password.');
        }

        // A suspended account is refused even with the right password. The
        // message is deliberately explicit: support staff handle these calls.
        if ($user->is_active === false) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw new UserError('This account has been suspended. Please contact the shop.');
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();

        // A fresh token per device, old ones stay valid until they expire.
        $token = $user->createToken('api')->plainTextToken;

        return [
            'user' => $user->fresh(),
            'token' => $token,
            'message' => 'Logged in successfully.',
        ];
    }

    private function throttleKey(string $email): string
    {
        return 'login|'.Str::transliterate($email).'|'.request()->ip();
    }
}
