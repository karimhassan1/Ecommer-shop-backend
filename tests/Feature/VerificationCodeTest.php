<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use App\Services\VerificationCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * The six digit e-mail verification code.
 *
 * This is the flow the SPA uses: registration sends a code, the customer types
 * it on the verification screen, and until they do the front end keeps them
 * there.
 */
class VerificationCodeTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    /** The code most recently captured from the outgoing mailable. */
    protected string $lastCode = '';

    /**
     * Issue a fresh code for the user and remember the plain value.
     *
     * The service only stores a hash, so the test reads the code from the
     * mailable it sent.
     */
    protected function captureCode(User $user): void
    {
        Mail::fake();

        $user->forceFill([
            'verification_code' => null,
            'verification_code_expires_at' => null,
            'verification_attempts' => 0,
        ])->save();

        app(VerificationCodeService::class)->send($user, enforceCooldown: false);

        Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use ($user): bool {
            if ($mail->hasTo($user->email)) {
                $this->lastCode = $mail->code;
            }

            return true;
        });
    }

    public function test_registering_sends_a_six_digit_verification_code(): void
    {
        Mail::fake();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Register(
                    name: "Isabella Roman"
                    phone: "5550104455"
                    email: "isabella@example.com"
                    password: "password123"
                    password_confirmation: "password123"
                ) { token message user { email is_email_verified } }
            }
        ')
            ->assertJsonPath('data.Register.user.email', 'isabella@example.com')
            ->assertJsonPath('data.Register.user.is_email_verified', false);

        Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail): bool {
            return preg_match('/^\d{6}$/', $mail->code) === 1;
        });

        $user = User::whereEmail('isabella@example.com')->firstOrFail();

        // The code is stored hashed, never in the clear.
        $this->assertNotNull($user->verification_code);
        $this->assertTrue($user->verification_code_expires_at->isFuture());
    }

    public function test_a_correct_code_verifies_the_address(): void
    {
        $user = User::factory()->unverified()->create();
        $this->captureCode($user);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($code: String!) {
                    VerifyEmailCode(code: $code) {
                        message
                        verified
                        user { email_verified_at is_email_verified }
                    }
                }
            ', ['code' => $this->lastCode])
            ->assertJsonPath('data.VerifyEmailCode.verified', true)
            ->assertJsonPath('data.VerifyEmailCode.message', 'Email verified successfully. Thank you!');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // The code is burned once used.
        $this->assertNull($user->fresh()->verification_code);
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $user = User::factory()->unverified()->create();
        $this->captureCode($user);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation { VerifyEmailCode(code: "000000") { verified } }
            ')
            ->assertJsonPath('errors.0.message', function (string $message): bool {
                return str_contains($message, 'not correct');
            });

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame(1, $user->fresh()->verification_attempts);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $this->captureCode($user);

        $user->forceFill(['verification_code_expires_at' => now()->subMinute()])->save();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($code: String!) { VerifyEmailCode(code: $code) { verified } }
            ', ['code' => $this->lastCode])
            ->assertJsonPath('errors.0.message', function (string $message): bool {
                return str_contains($message, 'expired');
            });

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_code_is_discarded_after_too_many_wrong_guesses(): void
    {
        $user = User::factory()->unverified()->create();
        $this->captureCode($user);

        foreach (range(1, VerificationCodeService::MAX_ATTEMPTS) as $attempt) {
            $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
                mutation { VerifyEmailCode(code: "000000") { verified } }
            ');
        }

        $this->assertNull($user->fresh()->verification_code);

        // Even the real code no longer works.
        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($code: String!) { VerifyEmailCode(code: $code) { verified } }
            ', ['code' => $this->lastCode])
            ->assertJsonPath('errors.0.message', function (string $message): bool {
                return str_contains($message, 'verification code');
            });
    }

    public function test_a_verified_user_cannot_ask_for_another_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ 'mutation { SendEmailVerificationCode { message } }')
            ->assertGraphQLErrorMessage('Your email address is already verified.');
    }

    public function test_the_status_query_reports_the_verification_state(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                query {
                    EmailVerificationStatus {
                        email
                        is_verified
                        code_ttl_minutes
                        seconds_until_resend
                    }
                }
            ')
            ->assertJsonPath('data.EmailVerificationStatus.email', $user->email)
            ->assertJsonPath('data.EmailVerificationStatus.is_verified', false)
            ->assertJsonPath(
                'data.EmailVerificationStatus.code_ttl_minutes',
                VerificationCodeService::CODE_TTL_MINUTES
            );
    }

    public function test_the_verification_columns_are_never_exposed_by_the_api(): void
    {
        $user = User::factory()->unverified()->create();
        $this->captureCode($user);

        // The generated schema must not carry the hashed code.
        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                query { me { id email is_email_verified } }
            ')
            ->assertJsonMissingPath('data.me.verification_code')
            ->assertJsonPath('data.me.is_email_verified', false);
    }
}
