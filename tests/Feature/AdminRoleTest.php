<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;
    use CreatesStaffUsers;

    public function test_an_admin_cannot_revoke_their_own_admin_access(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    ToggleAdminRole(id: '.$admin->id.', is_admin: false) { message }
                }
            ')
            ->assertGraphQLErrorMessage('You cannot remove your own administrator access.');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_an_admin_can_grant_administrator_access(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    ToggleAdminRole(id: '.$user->id.', is_admin: true) {
                        message
                        user { id is_admin }
                    }
                }
            ')
            ->assertJsonPath('data.ToggleAdminRole.user.is_admin', true);

        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_a_regular_user_cannot_promote_themselves(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    ToggleAdminRole(id: '.$user->id.', is_admin: true) { message }
                }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');

        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_a_user_cannot_read_another_users_order_through_the_generic_field(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $order = Order::create([
            'user_id' => $owner->id,
            'name' => 'Owner',
            'phone' => '555',
            'email' => $owner->email,
            'province' => 'CA',
            'city' => 'LA',
            'street' => '1 Rose Ave',
            'postal_code' => '90001',
            'total_amount' => 0,
            'status' => 'pending',
        ]);

        // The generic `order` field is admin only.
        $this->actingAs($intruder)
            ->graphQL(/** @lang GraphQL */ '
                query { order(id: '.$order->id.') { id total_amount } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');

        // MyOrders never returns somebody else's order.
        $this->actingAs($intruder)
            ->graphQL(/** @lang GraphQL */ '
                query { MyOrders { paginatorInfo { total } } }
            ')
            ->assertJsonPath('data.MyOrders.paginatorInfo.total', 0);
    }

    public function test_the_dashboard_reports_catalog_and_order_numbers(): void
    {
        $admin = $this->adminUser();
        User::factory()->count(3)->create();

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                query { AdminDashboard { products orders customers pending_orders revenue } }
            ')
            ->assertJsonPath('data.AdminDashboard.products', 0)
            ->assertJsonPath('data.AdminDashboard.orders', 0);
    }
}
