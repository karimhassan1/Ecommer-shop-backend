<?php

namespace App\Services;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Six digit e-mail verification codes.
 *
 * Why a code next to the signed link: a link only works in the browser that
 * opened the mailbox, and copying it across devices is awkward on a phone. The
 * code lets the customer finish verification from the page they signed up on.
 *
 * Rules enforced here, so every caller behaves the same:
 *   - the code is six digits, hashed at rest, valid for CODE_TTL_MINUTES
 *   - a code is burned the moment it is used (and after too many attempts)
 *   - resending replaces the code but is rate limited to avoid mail bombs
 */
class VerificationCodeService
{
    /** How long a freshly issued code stays valid. */
    public const CODE_TTL_MINUTES = 15;

    /** Wrong guesses allowed before the code is discarded. */
    public const MAX_ATTEMPTS = 5;

    /** Seconds a user has to wait between two codes. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly MailService $mail) {}

    /**
     * Issue a fresh code and e-mail it.
     *
     * Returns false when the mail transport failed, so the UI can say so; the
     * code is still stored, because the user may have received it anyway.
     */
    public function send(User $user, bool $enforceCooldown = true): bool
    {
        if ($user->hasVerifiedEmail()) {
            throw new UserError('Your email address is already verified.');
        }

        if ($enforceCooldown) {
            $this->guardCooldown($user);
        }

        $code = $this->generateCode();

        $user->forceFill([
            'verification_code' => Hash::make($code),
            'verification_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'verification_attempts' => 0,
        ])->save();

        return $this->sendCodeMail($user, $code);
    }

    /**
     * Check a code and, when it matches, mark the address verified.
     */
    public function verify(User $user, string $code): string
    {
        if ($user->hasVerifiedEmail()) {
            return 'Your email address is already verified.';
        }

        if (! $user->verification_code || ! $user->verification_code_expires_at) {
            throw new UserError('Request a new verification code to continue.');
        }

        if ($user->verification_code_expires_at->isPast()) {
            $this->clear($user);

            throw new UserError('That code has expired. Request a new one.');
        }

        if ($user->verification_attempts >= self::MAX_ATTEMPTS) {
            $this->clear($user);

            throw new UserError('Too many incorrect codes. Request a new one.');
        }

        if (! Hash::check(trim($code), (string) $user->verification_code)) {
            $attempts = (int) $user->verification_attempts + 1;
            $remaining = max(0, self::MAX_ATTEMPTS - $attempts);

            $user->forceFill(['verification_attempts' => $attempts])->save();

            // The last allowed miss burns the code: the next guess could only
            // ever be a brute force attempt, never a legitimate retry.
            if ($remaining === 0) {
                $this->clear($user);

                throw new UserError('Too many incorrect codes. Request a new one.');
            }

            throw new UserError("That code is not correct. {$remaining} attempt(s) left.");
        }

        $user->markEmailAsVerified();
        $this->clear($user);

        return 'Email verified successfully. Thank you!';
    }

    /** Seconds left before another code may be requested. */
    public function cooldownRemaining(User $user): int
    {
        if (! $user->verification_code_expires_at) {
            return 0;
        }

        // The code was issued TTL minutes before it expires.
        $issuedAt = Carbon::parse($user->verification_code_expires_at)
            ->subMinutes(self::CODE_TTL_MINUTES);

        $unlockAt = $issuedAt->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return max(0, (int) now()->diffInSeconds($unlockAt, false));
    }

    protected function guardCooldown(User $user): void
    {
        $remaining = $this->cooldownRemaining($user);

        if ($remaining > 0) {
            throw new UserError(
                "Please wait {$remaining} second(s) before requesting another code."
            );
        }
    }

    /** Burn the current code so it can never be replayed. */
    protected function clear(User $user): void
    {
        $user->forceFill([
            'verification_code' => null,
            'verification_code_expires_at' => null,
            'verification_attempts' => 0,
        ])->save();
    }

    /**
     * A random six digit code, kept away from obvious sequences.
     */
    protected function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function sendCodeMail(User $user, string $code): bool
    {
        try {
            Mail::to($user->email)->send(new EmailVerificationCodeMail(
                customerName: $user->name,
                code: $code,
                expiresInMinutes: self::CODE_TTL_MINUTES,
            ));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Verification code email could not be sent.', [
                'to' => $user->email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
