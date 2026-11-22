<?php

namespace Tests\Feature;

use App\Mail\WelcomeMail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Creating and editing staff accounts from the admin panel.
 *
 * The shop is run by several people with different jobs, so an administrator
 * has to be able to add a colleague, give them a role and change those details
 * later without ever touching the database.
 */
class SaveUserTest extends TestCase
{
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_an_admin_creates_a_staff_account_with_a_role(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveUser(
                        name: "Ayesha Khan"
                        email: "ayesha@example.com"
                        phone: "03001234567"
                        password: "secret-password"
                        roles: ["catalog-manager"]
                    ) {
                        id name email is_active role_list { name } permission_list
                    }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SaveUser.email', 'ayesha@example.com')
            ->assertJsonPath('data.SaveUser.is_active', true)
            ->assertJsonPath('data.SaveUser.role_list.0.name', 'catalog-manager');

        $created = User::whereEmail('ayesha@example.com')->firstOrFail();

        $this->assertTrue($created->hasRole(RoleSeeder::CATALOG));
        // A limited role must never become a full administrator.
        $this->assertFalse($created->isAdmin());
        $this->assertNotNull($created->cart);

        Mail::assertSent(WelcomeMail::class);
    }

    public function test_creating_a_customer_without_roles_grants_no_staff_access(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveUser(name: "Bilal", email: "bilal@example.com", password: "secret-password") {
                        id is_staff is_admin role_list { name }
                    }
                }
            ')
            ->assertJsonPath('data.SaveUser.is_staff', false)
            ->assertJsonPath('data.SaveUser.is_admin', false)
            ->assertJsonPath('data.SaveUser.role_list', []);
    }

    public function test_an_admin_edits_a_users_details_and_roles(): void
    {
        $admin = $this->adminUser();
        $this->seedPermissions();

        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'staff@example.com']);
        $user->assignRole(RoleSeeder::SUPPORT);

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) {
                    SaveUser(
                        id: $id
                        name: "New Name"
                        email: "staff@example.com"
                        phone: "03211234567"
                        roles: ["manager"]
                        is_active: false
                    ) {
                        id name phone is_active role_list { name }
                    }
                }
            ', ['id' => (string) $user->id])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SaveUser.name', 'New Name')
            ->assertJsonPath('data.SaveUser.phone', '03211234567')
            ->assertJsonPath('data.SaveUser.is_active', false)
            ->assertJsonPath('data.SaveUser.role_list.0.name', 'manager');

        $user->refresh();

        $this->assertTrue($user->hasRole(RoleSeeder::MANAGER));
        $this->assertFalse($user->hasRole(RoleSeeder::SUPPORT));
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveUser(name: "X", email: "x@example.com", password: "secret-password", roles: ["wizard"]) { id }
                }
            ')
            ->assertGraphQLErrorMessage('Unknown role: wizard. Create it under Roles first.');

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveUser(name: "X", email: "taken@example.com", password: "secret-password") { id }
                }
            ')
            ->assertGraphQLErrorMessage('That email address is already registered.');
    }

    public function test_a_staff_member_cannot_remove_their_own_access(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) {
                    SaveUser(id: $id, name: "Me", email: "me@example.com", roles: []) { id }
                }
            ', ['id' => (string) $admin->id])
            ->assertGraphQLErrorMessage('You cannot remove your own admin access.');
    }

    public function test_a_regular_customer_cannot_create_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveUser(name: "X", email: "x@example.com", password: "secret-password") { id }
                }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $user->forceFill(['is_active' => false])->save();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Login(email: "'.$user->email.'", password: "password123") { token }
            }
        ')->assertGraphQLErrorMessage('This account has been suspended. Please contact the shop.');
    }
}
