<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * The one administrator account, and what a seller may see in the panel.
 *
 * "Remove all other users, only the admin for now" is a deployment requirement:
 * the seeder must leave exactly one working account behind and disable the demo
 * ones. The second half pins the seller's narrowed view, because a seller passes
 * the panel gate and must still only ever see their own catalogue.
 */
class AdminAccountTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_the_seeder_creates_only_the_owner_account(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::whereEmail(AdminUserSeeder::ADMIN_EMAIL)->firstOrFail();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasRole(RoleSeeder::SUPER_ADMIN));
        $this->assertTrue(\Hash::check('password', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($admin->cart, 'The panel needs a cart for the owner.');

        // Nothing else was created.
        $this->assertSame(1, User::count());
    }

    public function test_the_owner_can_sign_in_with_the_given_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                Login(email: "'.AdminUserSeeder::ADMIN_EMAIL.'", password: "password") {
                    token
                    user { email is_admin is_staff permission_list }
                }
            }
        ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.Login.user.email', AdminUserSeeder::ADMIN_EMAIL)
            ->assertJsonPath('data.Login.user.is_admin', true)
            // A super admin holds the whole catalogue, which is what makes every
            // admin screen reachable in one round trip.
            ->assertJsonPath('data.Login.user.permission_list', fn (array $list): bool => in_array('user.update', $list, true));
    }

    public function test_the_retired_demo_accounts_are_disabled_and_lose_their_roles(): void
    {
        $this->seedPermissions();

        $demo = User::factory()->create([
            'email' => 'manager@roserabloom.test',
            'is_admin' => true,
        ]);
        $demo->assignRole(RoleSeeder::MANAGER);

        $this->seed(AdminUserSeeder::class);

        $demo = $demo->fresh();

        $this->assertFalse($demo->isAdmin());
        $this->assertFalse($demo->is_active);
        $this->assertSame(0, $demo->roles()->count());

        // And they can no longer sign in.
        $this->graphQL(/** @lang GraphQL */ '
            mutation { Login(email: "manager@roserabloom.test", password: "password") { token } }
        ')->assertGraphQLErrorMessage('This account has been suspended. Please contact the shop.');
    }

    public function test_a_seller_only_moderates_reviews_of_their_own_products(): void
    {
        $seller = $this->staffUser(RoleSeeder::SELLER);
        $other = $this->staffUser(RoleSeeder::SELLER);

        $mine = $this->perfumeProduct(['seller_id' => $seller->id]);
        $theirs = $this->perfumeProduct(['seller_id' => $other->id]);

        $this->reviewOn($mine, 'Mine', true);
        $this->reviewOn($theirs, 'Theirs', true);

        $response = $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                query { AdminReviews(first: 20) { data { title product { id } } } }
            ')
            ->assertJsonMissingPath('errors');

        $titles = array_column($response->json('data.AdminReviews.data'), 'title');

        $this->assertSame(['Mine'], $titles);
    }

    public function test_an_administrator_moderates_every_review(): void
    {
        $seller = $this->staffUser(RoleSeeder::SELLER);
        $product = $this->perfumeProduct(['seller_id' => $seller->id]);

        $this->reviewOn($product, 'Pending one', false);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                query { AdminReviews(first: 20) { data { title is_approved } } }
            ')
            ->assertJsonPath('data.AdminReviews.data.0.title', 'Pending one')
            ->assertJsonPath('data.AdminReviews.data.0.is_approved', false);
    }

    public function test_a_seller_cannot_read_another_sellers_order_by_id(): void
    {
        $seller = $this->staffUser(RoleSeeder::SELLER);
        $other = $this->staffUser(RoleSeeder::SELLER);
        $product = $this->perfumeProduct(['seller_id' => $other->id]);

        $order = Order::create([
            'user_id' => $other->id,
            'name' => 'Buyer',
            'phone' => '03001234567',
            'email' => 'buyer@example.com',
            'province' => 'Punjab',
            'city' => 'Lahore',
            'street' => '1 Rose Ave',
            'subtotal' => 1000,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'status' => Order::STATUS_PENDING,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $this->defaultVariant($product)->id,
            'quantity' => 1,
            'price' => 1000,
        ]);

        // The generic lookup is shop-side only, so a seller gets a permission
        // error instead of somebody else's delivery address.
        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                query { order(id: '.$order->id.') { id street city } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_a_seller_cannot_enumerate_order_lines_or_carts(): void
    {
        $seller = $this->staffUser(RoleSeeder::SELLER);

        foreach ([
            'query { order_items(first: 5) { data { id } } }',
            'query { carts(first: 5) { data { id } } }',
            'query { cart_items(first: 5) { data { id } } }',
        ] as $query) {
            $this->actingAs($seller)
                ->graphQL($query)
                ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
        }
    }

    public function test_a_seller_only_sees_the_brands_and_categories_they_use(): void
    {
        $seller = $this->staffUser(RoleSeeder::SELLER);
        $other = $this->staffUser(RoleSeeder::SELLER);

        $mineBrand = \App\Models\Brand::create(['name' => 'My Label']);
        $theirBrand = \App\Models\Brand::create(['name' => 'Their Label']);

        $myProduct = $this->perfumeProduct(['seller_id' => $seller->id]);
        $myProduct->brands()->attach($mineBrand->id);

        $theirProduct = $this->perfumeProduct(['seller_id' => $other->id]);
        $theirProduct->brands()->attach($theirBrand->id);

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ 'query { AdminBrands { name } }')
            ->assertJsonPath('data.AdminBrands.0.name', 'My Label')
            ->assertJsonCount(1, 'data.AdminBrands');

        // The administrator still sees both.
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ 'query { AdminBrands { name } }')
            ->assertJsonCount(2, 'data.AdminBrands');
    }

    /** Attach one review to a product. */
    protected function reviewOn(Product $product, string $title, bool $approved): void
    {
        Review::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 5,
            'title' => $title,
            'body' => 'A lovely piece, exactly as pictured.',
            'is_approved' => $approved,
        ]);
    }
}
