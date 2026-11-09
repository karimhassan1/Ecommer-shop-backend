<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_welcome_and_verification_mail(): void
    {
        Mail::fake();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Register(
                    name: "Isabella Roman"
                    phone: "5550104455"
                    email: "isabella@example.com"
                    password: "password123"
                    password_confirmation: "password123"
                ) {
                    token
                    message
                    user { id email is_admin email_verified_at }
                }
            }
        ');

        $response->assertJsonPath('data.Register.user.email', 'isabella@example.com');
        $response->assertJsonPath('data.Register.user.is_admin', false);
        $response->assertJsonPath('data.Register.user.email_verified_at', null);
        $this->assertNotEmpty($response->json('data.Register.token'));

        Mail::assertSent(WelcomeMail::class);
        Mail::assertSent(EmailVerificationMail::class);

        // A brand new user always owns a cart.
        $this->assertNotNull(User::whereEmail('isabella@example.com')->first()->cart);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Register(
                    name: "Someone"
                    phone: "555"
                    email: "taken@example.com"
                    password: "password123"
                    password_confirmation: "password123"
                ) { token }
            }
        ')->assertGraphQLValidationError('email', 'The email has already been taken.');
    }

    public function test_a_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $login = $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Login(email: "'.$user->email.'", password: "password123") {
                    token
                    user { id email }
                }
            }
        ')->assertJsonPath('data.Login.user.id', (string) $user->id);

        $token = $login->json('data.Login.token');
        $this->assertNotNull($token);
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->graphQL(/** @lang GraphQL */ 'mutation { Logout { message } }')
            ->assertJsonPath('data.Logout.message', 'Logged out successfully.');

        // The token used for this request is revoked, so it cannot authenticate
        // any further request made by a real client.
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Login(email: "'.$user->email.'", password: "wrong-password") { token }
            }
        ')->assertGraphQLErrorMessage('Invalid email or password.');
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        foreach (range(1, 5) as $attempt) {
            $this->graphQL(/** @lang GraphQL */ '
                mutation {
                    Login(email: "'.$user->email.'", password: "nope") { token }
                }
            ');
        }

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Login(email: "'.$user->email.'", password: "nope") { token }
            }
        ')->assertJsonPath('errors.0.message', function (string $message): bool {
            return str_contains($message, 'Too many login attempts');
        });
    }

    public function test_a_user_can_change_their_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    ResetPassword(
                        current_password: "old-password"
                        password: "new-password"
                        password_confirmation: "new-password"
                    ) { message }
                }
            ')
            ->assertJsonPath('data.ResetPassword.message', 'Password updated successfully.');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    ResetPassword(
                        current_password: "wrong"
                        password: "new-password"
                        password_confirmation: "new-password"
                    ) { message }
                }
            ')
            ->assertGraphQLErrorMessage('Your current password is incorrect.');
    }
}
