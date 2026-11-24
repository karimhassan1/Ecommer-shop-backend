<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * An order line keeps its own copy of what was sold.
 *
 * Prices, names and discounts change all the time. Once an order is placed, its
 * lines must describe the sale exactly as it happened: what was bought, how
 * many, at which price and with which discount. These tests pin that down from
 * both directions — the stored row and the GraphQL payload.
 */
class OrderSnapshotTest extends TestCase
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

    /** Put one variant of a product in the buyer's cart and place the order. */
    protected function orderFor(User $buyer, Product $product, int $variantId, int $quantity = 1): Order
    {
        $this->actingAs($buyer)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID, $quantity: Int!) {
                AddToCart(product_id: $product, product_variant_id: $variant, quantity: $quantity) {
                    items_count
                }
            }
        ', [
            'product' => (string) $product->id,
            'variant' => (string) $variantId,
            'quantity' => $quantity,
        ])->assertJsonMissingPath('errors');

        $id = $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address()])
            ->assertJsonMissingPath('errors')
            ->json('data.PlaceOrder.id');

        return Order::with('items')->findOrFail((int) $id);
    }

    public function test_the_order_line_stores_the_name_price_and_discount_at_checkout(): void
    {
        $buyer = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        // A live discount: 6500 -> 5000, so the line has a list price and a
        // discount to remember.
        $variant->forceFill(['discount_price' => 5000])->save();

        $order = $this->orderFor($buyer, $product, $variant->id, 2);
        $line = $order->items->first();

        $this->assertSame($product->name, $line->product_name);
        $this->assertSame(Product::TYPE_CLOTHING, $line->product_type);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(5000.0, (float) $line->price);
        $this->assertSame(6500.0, (float) $line->list_price);
        $this->assertSame(1500.0, (float) $line->discount_amount);
        $this->assertSame(3000.0, $line->savings);
        $this->assertSame(10000.0, $line->line_total);
    }

    public function test_a_later_price_change_does_not_rewrite_a_placed_order(): void
    {
        $buyer = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $order = $this->orderFor($buyer, $product, $variant->id);

        // The catalogue moves on: the price jumps and the product is renamed.
        $variant->forceFill(['price' => 9999])->save();
        $product->forceFill(['name' => 'Silk Bloom Midi Dress (2027)'])->save();

        $line = $order->items()->first();

        $this->assertSame(6500.0, (float) $line->price);
        $this->assertSame($product->name, $line->product->name, 'The live product did change');
        $this->assertSame('Silk Bloom Midi Dress', $line->display_name, 'The snapshot did not');

        // And the API reports the same frozen values to the customer.
        // (Total = 6500 items + the default Rs 100 cash-on-delivery fee.)
        $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                query { MyOrders(first: 5) { data { total_amount items { display_name price list_price line_total } } } }
            ')
            ->assertJsonPath('data.MyOrders.data.0.total_amount', 6600)
            ->assertJsonPath('data.MyOrders.data.0.items.0.display_name', 'Silk Bloom Midi Dress')
            ->assertJsonPath('data.MyOrders.data.0.items.0.price', 6500);
    }

    public function test_a_product_that_has_been_ordered_cannot_be_deleted(): void
    {
        $buyer = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $order = $this->orderFor($buyer, $product, $variant->id);

        // Deleting it would break the order line's foreign key and lose the
        // history, so the shop is told to deactivate it instead.
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { DeleteProduct(id: $id) { message } }
            ', ['id' => (string) $product->id])
            ->assertGraphQLErrorMessage(
                'This product appears on an order, so it cannot be deleted. Hide it from the storefront instead.'
            );

        $line = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->assertNotNull($product->fresh());
        $this->assertSame('Silk Bloom Midi Dress', $line->display_name);
        $this->assertSame(6500.0, (float) $line->price);
    }
}
