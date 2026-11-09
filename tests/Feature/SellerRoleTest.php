<?php

namespace Tests\Feature;

use App\Mail\SellerOrderPlacedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * The seller role.
 *
 * A seller is staff, not an administrator: they may open the panel and manage
 * their own catalogue and the orders their products appear in, and nothing
 * else. These tests pin that boundary in both directions.
 */
class SellerRoleTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function seller(array $attributes = []): User
    {
        return $this->staffUser(RoleSeeder::SELLER, $attributes);
    }

    public function test_a_seller_is_staff_but_not_an_administrator(): void
    {
        $seller = $this->seller();

        $this->assertTrue($seller->isSeller());
        $this->assertTrue($seller->isStaff());
        $this->assertFalse($seller->isAdmin());

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ 'query { me { is_seller is_staff is_admin } }')
            ->assertJsonPath('data.me.is_seller', true)
            ->assertJsonPath('data.me.is_staff', true)
            ->assertJsonPath('data.me.is_admin', false);
    }

    public function test_a_seller_only_lists_their_own_products(): void
    {
        $seller = $this->seller();
        $otherSeller = $this->seller();

        $this->perfumeProduct(['name' => 'Mine', 'seller_id' => $seller->id]);
        $this->perfumeProduct(['name' => 'Theirs', 'seller_id' => $otherSeller->id]);
        $this->perfumeProduct(['name' => 'Shop owned']);

        $response = $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                query { BrowseProducts(first: 20, include_inactive: true) { data { name } } }
            ')
            ->assertJsonPath('data.BrowseProducts.data.0.name', 'Mine');

        $names = array_column($response->json('data.BrowseProducts.data'), 'name');

        $this->assertSame(['Mine'], $names);
    }

    public function test_a_created_product_belongs_to_the_seller(): void
    {
        $seller = $this->seller();
        $ids = $this->masterIds();

        $productId = $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($v: [VariantInput!]!) {
                    CreateProduct(type: "perfume", name: "Seller Oud", variants: $v) {
                        id seller_id seller_name
                    }
                }
            ', ['v' => [[
                'volume_id' => $ids['thirty'],
                'price' => 4500,
                'stock' => 3,
                'is_default' => true,
                'images' => [['url' => 'seller.jpg']],
            ]]])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.CreateProduct.seller_id', (string) $seller->id)
            ->json('data.CreateProduct.id');

        $this->assertSame($seller->id, Product::find($productId)->seller_id);
    }

    public function test_a_seller_cannot_edit_another_sellers_product(): void
    {
        $seller = $this->seller();
        $otherSeller = $this->seller();
        $product = $this->perfumeProduct(['seller_id' => $otherSeller->id]);

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { UpdateProduct(id: $id, name: "Hijacked") { id name } }
            ', ['id' => (string) $product->id])
            ->assertGraphQLErrorMessage('You can only edit products you sell.');

        $this->assertSame('Rose Oud Eau de Parfum', $product->fresh()->name);
    }

    public function test_a_seller_can_edit_their_own_product(): void
    {
        $seller = $this->seller();
        $product = $this->perfumeProduct(['seller_id' => $seller->id]);

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { UpdateProduct(id: $id, name: "Renamed") { id name } }
            ', ['id' => (string) $product->id])
            ->assertJsonPath('data.UpdateProduct.name', 'Renamed');
    }

    public function test_a_seller_cannot_delete_another_sellers_product(): void
    {
        $seller = $this->seller();
        $otherSeller = $this->seller();
        $product = $this->perfumeProduct(['seller_id' => $otherSeller->id]);

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { DeleteProduct(id: $id) { message } }
            ', ['id' => (string) $product->id])
            ->assertGraphQLErrorMessage('You can only delete products you sell.');

        $this->assertNotNull($product->fresh());
    }

    public function test_a_seller_only_sees_orders_containing_their_products(): void
    {
        $seller = $this->seller();
        $otherSeller = $this->seller();

        $mine = $this->perfumeProduct(['seller_id' => $seller->id]);
        $theirs = $this->perfumeProduct(['seller_id' => $otherSeller->id]);

        $myOrder = $this->orderFor($mine, $seller->id, 'mine@example.com');
        $theirOrder = $this->orderFor($theirs, $otherSeller->id, 'theirs@example.com');

        $response = $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                query { ManageOrders(first: 20) { data { id items { product_id } } } }
            ')
            ->assertJsonPath('data.ManageOrders.data.0.id', (string) $myOrder->id);

        $ids = array_map('intval', array_column($response->json('data.ManageOrders.data'), 'id'));

        $this->assertSame([$myOrder->id], $ids);
        $this->assertNotContains($theirOrder->id, $ids);

        // The line items are trimmed to the seller's own products.
        $this->assertSame(
            [$mine->id],
            array_column($response->json('data.ManageOrders.data.0.items'), 'product_id')
        );
    }

    public function test_an_administrator_sees_every_order_even_through_manage_orders(): void
    {
        $admin = $this->adminUser();
        $seller = $this->seller();
        $product = $this->perfumeProduct(['seller_id' => $seller->id]);

        $this->orderFor($product, $seller->id, 'buyer@example.com');

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ 'query { ManageOrders(first: 20) { paginatorInfo { total } } }')
            ->assertJsonPath('data.ManageOrders.paginatorInfo.total', 1);
    }

    public function test_a_seller_dashboard_only_counts_their_own_shop(): void
    {
        $seller = $this->seller();
        $otherSeller = $this->seller();

        $mine = $this->perfumeProduct(['seller_id' => $seller->id]);
        $this->perfumeProduct(['seller_id' => $otherSeller->id]);

        $this->orderFor($mine, $seller->id, 'buyer@example.com');

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                query { AdminDashboard { products orders customers } }
            ')
            ->assertJsonPath('data.AdminDashboard.products', 1)
            ->assertJsonPath('data.AdminDashboard.orders', 1)
            // A seller never sees the customer book.
            ->assertJsonPath('data.AdminDashboard.customers', 0);
    }

    public function test_placing_an_order_emails_the_customer_and_the_seller(): void
    {
        Mail::fake();

        $seller = $this->seller();
        $product = $this->perfumeProduct(['seller_id' => $seller->id]);
        $buyer = User::factory()->create();

        $this->fillCartFor($buyer, $product);

        $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => [
                'name' => 'Buyer',
                'phone' => '03001234567',
                'email' => 'buyer@example.com',
                'province' => 'Punjab',
                'city' => 'Lahore',
                'street' => '1 Rose Ave',
                'postal_code' => '54000',
            ]])
            ->assertJsonMissingPath('errors');

        // The receipt and the seller notification both leave the building.
        Mail::assertSent(SellerOrderPlacedMail::class, function (SellerOrderPlacedMail $mail) use ($seller): bool {
            return $mail->hasTo($seller->email) && $mail->lines->count() === 1;
        });
    }

    /**
     * Build and persist an order that contains exactly one line.
     */
    protected function orderFor(Product $product, int $customerId, string $email): Order
    {
        $order = Order::create([
            'user_id' => $customerId,
            'name' => 'Buyer',
            'phone' => '03001234567',
            'email' => $email,
            'province' => 'Punjab',
            'city' => 'Lahore',
            'street' => '1 Rose Ave',
            'postal_code' => '54000',
            'subtotal' => 1000,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'status' => Order::STATUS_PENDING,
        ]);

        $variant = $this->defaultVariant($product);

        $order->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'price' => 1000,
        ]);

        return $order->fresh();
    }

    /** Put one variant of a product in the buyer's cart. */
    protected function fillCartFor(User $buyer, Product $product): void
    {
        $variant = $this->defaultVariant($product);

        app(\App\Services\CartService::class)
            ->addItem($buyer, $product->id, 1, $variant->id);
    }
}
