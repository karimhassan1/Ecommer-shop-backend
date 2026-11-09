<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class EmailFlowTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_a_registered_user_can_verify_their_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                VerifyEmail(id: '.$user->id.', hash: "'.sha1($user->email).'") { message }
            }
        ')->assertJsonPath('data.VerifyEmail.message', 'Email verified successfully. Thank you!');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_rejects_a_tampered_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                VerifyEmail(id: '.$user->id.', hash: "not-a-real-hash") { message }
            }
        ')->assertGraphQLErrorMessage('This verification link is not valid.');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_user_can_request_a_new_verification_email(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation { ResendVerificationEmail { message } }
            ')
            ->assertJsonPath('data.ResendVerificationEmail.message', fn (string $m): bool => str_contains($m, 'verification link'));

        Mail::assertSent(EmailVerificationMail::class);
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

    public function test_a_user_can_request_a_password_reset_link(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ForgotPassword(email: "'.$user->email.'") { message }
            }
        ')->assertJsonPath('data.ForgotPassword.message', fn (string $m): bool => str_contains($m, 'password reset link'));

        Mail::assertSent(PasswordResetMail::class);
    }

    public function test_an_unknown_email_does_not_leak_registration_state(): void
    {
        Mail::fake();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ForgotPassword(email: "nobody@example.com") { message }
            }
        ')->assertJsonPath('data.ForgotPassword.message', fn (string $m): bool => str_contains($m, 'password reset link'));

        Mail::assertNothingSent();
    }

    public function test_a_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ConfirmForgotPassword(
                    email: "'.$user->email.'"
                    token: "'.$token.'"
                    password: "brand-new-password"
                    password_confirmation: "brand-new-password"
                ) { message }
            }
        ')->assertJsonPath('data.ConfirmForgotPassword.message', 'Password reset successfully. You can now sign in.');

        $this->assertTrue(\Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_a_password_reset_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                ConfirmForgotPassword(
                    email: "'.$user->email.'"
                    token: "invalid-token"
                    password: "brand-new-password"
                    password_confirmation: "brand-new-password"
                ) { message }
            }
        ')->assertGraphQLErrorMessage('This password reset link is invalid or has expired.');
    }
}
