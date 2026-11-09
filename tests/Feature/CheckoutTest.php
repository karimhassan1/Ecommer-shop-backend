<?php

namespace Tests\Feature;

use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusChangedMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Checkout: the cart becomes an order, stock is decremented on the variant and
 * the order line snapshots the chosen colour/size (or volume) label.
 */
class CheckoutTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function address(): array
    {
        return [
            'name' => 'Isabella Roman',
            'phone' => '03001234567',
            'email' => 'isabella@example.com',
            'province' => 'Punjab',
            'city' => 'Lahore',
            'street' => '12 Rose Avenue',
            'postal_code' => '54000',
        ];
    }

    protected function fillCart(User $user, int $productId, int $variantId, int $quantity = 1): void
    {
        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID, $quantity: Int!) {
                AddToCart(product_id: $product, product_variant_id: $variant, quantity: $quantity) {
                    items_count
                }
            }
        ', [
            'product' => (string) $productId,
            'variant' => (string) $variantId,
            'quantity' => $quantity,
        ])->assertJsonMissingPath('errors');
    }

    public function test_placing_an_order_creates_the_order_and_sends_a_confirmation(): void
    {
        $user = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->fillCart($user, $product->id, $variant->id, 2);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) {
                        id
                        status
                        subtotal
                        total_amount
                        items {
                            quantity
                            price
                            line_total
                            variant_label
                            product { name }
                        }
                    }
                }
            ', ['address' => $this->address()])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.PlaceOrder.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.PlaceOrder.subtotal', 13000)
            ->assertJsonPath('data.PlaceOrder.items.0.price', 6500)
            ->assertJsonPath('data.PlaceOrder.items.0.line_total', 13000)
            ->assertJsonPath('data.PlaceOrder.items.0.variant_label', 'Rose Pink / M')
            ->assertJsonPath('data.PlaceOrder.items.0.product.name', 'Silk Bloom Midi Dress');

        Mail::assertSent(OrderConfirmationMail::class);
    }

    public function test_placing_an_order_empties_the_cart_and_reduces_variant_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->fillCart($user, $product->id, $variant->id, 3);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address()])
            ->assertJsonMissingPath('errors');

        $this->assertSame(7, $variant->fresh()->stock);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ 'query { MyCart { items_count } }')
            ->assertJsonPath('data.MyCart.items_count', 0);
    }

    public function test_an_empty_cart_cannot_be_checked_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address()])
            ->assertGraphQLErrorMessage('Your cart is empty.');
    }

    public function test_a_user_only_sees_their_own_orders(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->fillCart($buyer, $product->id, $variant->id, 1);

        $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address()])
            ->assertJsonMissingPath('errors');

        $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ 'query { MyOrders { paginatorInfo { total } } }')
            ->assertJsonPath('data.MyOrders.paginatorInfo.total', 1);

        $this->actingAs($other)
            ->graphQL(/** @lang GraphQL */ 'query { MyOrders { paginatorInfo { total } } }')
            ->assertJsonPath('data.MyOrders.paginatorInfo.total', 0);
    }

    public function test_an_admin_moves_an_order_forward_and_the_customer_is_notified(): void
    {
        $buyer = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->fillCart($buyer, $product->id, $variant->id, 1);

        $orderId = $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address()])
            ->json('data.PlaceOrder.id');

        Mail::fake();

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!, $status: String!) {
                    UpdateOrderStatus(id: $id, status: $status) { id status status_label }
                }
            ', ['id' => (string) $orderId, 'status' => Order::STATUS_ON_WAY])
            ->assertJsonPath('data.UpdateOrderStatus.status', Order::STATUS_ON_WAY)
            ->assertJsonPath('data.UpdateOrderStatus.status_label', 'On the way');

        Mail::assertSent(OrderStatusChangedMail::class);
    }

    public function test_a_regular_user_cannot_change_an_order_status(): void
    {
        $buyer = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->fillCart($buyer, $product->id, $variant->id, 1);

        $orderId = $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address()])
            ->json('data.PlaceOrder.id');

        $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!, $status: String!) {
                    UpdateOrderStatus(id: $id, status: $status) { id }
                }
            ', ['id' => (string) $orderId, 'status' => Order::STATUS_ON_WAY])
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }
}
