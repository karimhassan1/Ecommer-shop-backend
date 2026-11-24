<?php declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Six digit one time codes for "forgot password".
 *
 * The flow is deliberately the same shape as e-mail verification: the customer
 * asks for a code, receives six digits and types them with the new password.
 * No signed link is e-mailed, because a link only works in the browser that
 * opened the mailbox and forwarding it is trivially insecure.
 *
 * Rules enforced here, so every caller behaves the same:
 *   - the code is six digits, hashed at rest, valid for CODE_TTL_MINUTES
 *   - a code is burned the moment it is used (and after too many attempts)
 *   - requesting a new code is throttled, and issuing one resets the counter
 */
class PasswordResetCodeService
{
    /** How long a freshly issued code stays valid. */
    public const CODE_TTL_MINUTES = 15;

    /** Wrong guesses allowed before the code is discarded. */
    public const MAX_ATTEMPTS = 5;

    /** Seconds a customer has to wait between two codes for one address. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Issue a fresh code for an address and e-mail it.
     *
     * Returns true when a code was generated. Unknown addresses return false
     * without sending anything, which is what keeps the mutation from leaking
     * who has an account.
     */
    public function send(string $email): bool
    {
        $user = $this->findUser($email);

        if (! $user) {
            return false;
        }

        $this->guardCooldown($user);

        $code = $this->generateCode();

        $user->forceFill([
            'password_reset_code' => Hash::make($code),
            'password_reset_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'password_reset_attempts' => 0,
        ])->save();

        app(MailService::class)->sendPasswordResetCode($user, $code, self::CODE_TTL_MINUTES);

        return true;
    }

    /**
     * Check a code and, when it matches, set the new password.
     *
     * The code is burned and every API token is revoked, so an attacker who
     * already had a session cannot keep using it after the reset.
     */
    public function reset(string $email, string $code, string $password): void
    {
        $user = $this->findUser($email);

        // Same message for "no such address" and "bad code": the caller must
        // not be able to turn this into an account enumeration oracle.
        if (! $user || ! $user->password_reset_code || ! $user->password_reset_code_expires_at) {
            throw new UserError('That reset code is not valid. Request a new one.');
        }

        if ($user->password_reset_code_expires_at->isPast()) {
            $this->clear($user);

            throw new UserError('That reset code has expired. Request a new one.');
        }

        if ($user->password_reset_attempts >= self::MAX_ATTEMPTS) {
            $this->clear($user);

            throw new UserError('Too many incorrect codes. Request a new one.');
        }

        if (! Hash::check(trim($code), (string) $user->password_reset_code)) {
            $attempts = (int) $user->password_reset_attempts + 1;
            $remaining = max(0, self::MAX_ATTEMPTS - $attempts);

            $user->forceFill(['password_reset_attempts' => $attempts])->save();

            if ($remaining === 0) {
                $this->clear($user);

                throw new UserError('Too many incorrect codes. Request a new one.');
            }

            throw new UserError("That code is not correct. {$remaining} attempt(s) left.");
        }

        $user->forceFill(['password' => $password])->save();

        // A password reset must terminate every existing session.
        $user->tokens()->delete();

        $this->clear($user);
    }

    /** Seconds left before another code may be requested for this address. */
    public function cooldownRemaining(string $email): int
    {
        return (int) RateLimiter::availableIn($this->throttleKey($email));
    }

    /** The account behind an address, matched case-insensitively. */
    protected function findUser(string $email): ?User
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        return $user;
    }

    /**
     * Refuse a second code inside the cooldown window.
     *
     * Without this an attacker could use the "forgot password" form as a way to
     * flood somebody's inbox.
     */
    protected function guardCooldown(User $user): void
    {
        $key = $this->throttleKey($user->email);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            throw new UserError(
                "A reset code was just sent. Please wait {$seconds} second(s) before asking for another."
            );
        }

        RateLimiter::hit($key, self::RESEND_COOLDOWN_SECONDS);
    }

    /** Forget the stored code and its counters. */
    protected function clear(User $user): void
    {
        $user->forceFill([
            'password_reset_code' => null,
            'password_reset_code_expires_at' => null,
            'password_reset_attempts' => 0,
        ])->save();
    }

    /** A zero padded six digit code, cryptographically random. */
    protected function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function throttleKey(string $email): string
    {
        return 'password-reset|'.strtolower(trim($email)).'|'.request()->ip();
    }
}
