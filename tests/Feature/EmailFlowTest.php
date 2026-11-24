<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCodeMail;
use App\Mail\PasswordResetCodeMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\PasswordResetCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class EmailFlowTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    /**
     * Verification is done with the six digit code only; there is no signed
     * link any more, because the code is what the SPA collects on screen.
     */
    public function test_the_signed_verification_link_is_gone(): void
    {
        $user = User::factory()->unverified()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                VerifyEmail(id: '.$user->id.', hash: "'.sha1($user->email).'") { message }
            }
        ')->assertGraphQLErrorMessage('Cannot query field "VerifyEmail" on type "Mutation". Did you mean "VerifyEmailCode"?');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_user_can_request_a_new_verification_code(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation { ResendVerificationEmail { message } }
            ')
            ->assertJsonPath('data.ResendVerificationEmail.message', fn (string $m): bool => str_contains($m, '6 digit code'));

        Mail::assertSent(EmailVerificationCodeMail::class);
    }

    public function test_a_verified_user_cannot_request_another_verification_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation { ResendVerificationEmail { message } }
            ')
            ->assertGraphQLErrorMessage('Your email address is already verified.');

        Mail::assertNothingSent();
    }

    public function test_a_user_can_request_a_password_reset_code(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ForgotPassword(email: "'.$user->email.'") { message expires_in_minutes }
            }
        ')
            ->assertJsonPath('data.ForgotPassword.message', fn (string $m): bool => str_contains($m, '6 digit reset code'))
            ->assertJsonPath('data.ForgotPassword.expires_in_minutes', PasswordResetCodeService::CODE_TTL_MINUTES);

        // No link any more: the mail carries a six digit code and nothing else.
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use ($user): bool {
            return $mail->hasTo($user->email) && preg_match('/^\d{6}$/', $mail->code) === 1;
        });
        Mail::assertNotSent(PasswordResetMail::class);

        $this->assertNotNull($user->fresh()->password_reset_code_expires_at);
    }

    public function test_an_unknown_email_does_not_leak_registration_state(): void
    {
        Mail::fake();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ForgotPassword(email: "nobody@example.com") { message }
            }
        ')->assertJsonPath('data.ForgotPassword.message', fn (string $m): bool => str_contains($m, '6 digit reset code'));

        Mail::assertNothingSent();
    }

    public function test_a_password_can_be_reset_with_the_emailed_code(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ForgotPassword(email: "'.$user->email.'") { message }
            }
        ');

        $code = '';

        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ConfirmForgotPassword(
                    email: "'.$user->email.'"
                    code: "'.$code.'"
                    password: "brand-new-password"
                    password_confirmation: "brand-new-password"
                ) { message }
            }
        ')->assertJsonPath('data.ConfirmForgotPassword.message', 'Password reset successfully. You can now sign in.');

        $this->assertTrue(\Hash::check('brand-new-password', $user->fresh()->password));

        // The code is burned and its counters cleared once it has been used.
        $this->assertNull($user->fresh()->password_reset_code);
    }

    public function test_a_freshly_issued_code_does_not_need_a_second_request(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        // Requesting twice in a row is throttled, so the second answer never
        // reveals whether the address exists — it just tells the caller to wait.
        $this->graphQL(/** @lang GraphQL */ '
            mutation { ForgotPassword(email: "'.$user->email.'") { message } }
        ')->assertJsonMissingPath('errors');

        $this->graphQL(/** @lang GraphQL */ '
            mutation { ForgotPassword(email: "'.$user->email.'") { message } }
        ')->assertJsonPath('errors.0.message', function (string $message): bool {
            return str_contains($message, 'Please wait');
        });
    }

    public function test_a_password_reset_rejects_an_invalid_code(): void
    {
        $user = User::factory()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ConfirmForgotPassword(
                    email: "'.$user->email.'"
                    code: "000000"
                    password: "brand-new-password"
                    password_confirmation: "brand-new-password"
                ) { message }
            }
        ')->assertGraphQLErrorMessage('That reset code is not valid. Request a new one.');
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation { ForgotPassword(email: "'.$user->email.'") { message } }
        ');

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ConfirmForgotPassword(
                    email: "'.$user->email.'"
                    code: "999999"
                    password: "brand-new-password"
                    password_confirmation: "brand-new-password"
                ) { message }
            }
        ')->assertJsonPath('errors.0.message', function (string $message): bool {
            return str_contains($message, 'not correct');
        });

        $this->assertSame(1, (int) $user->fresh()->password_reset_attempts);
        $this->assertFalse(\Hash::check('brand-new-password', $user->fresh()->password));
    }
}
