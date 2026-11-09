<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Shipping fee, COD fee and address validation at checkout.
 *
 * Prices come from the variant, so these tests build a product whose single
 * variant costs the amount under test.
 */
class ShippingSettingsTest extends TestCase
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

    /** A product whose only variant costs `$price` and has enough stock. */
    protected function productAt(float $price, int $stock = 10): array
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);
        $variant->forceFill(['price' => $price, 'stock' => $stock])->save();

        return [$product, $variant->fresh()];
    }

    protected function address(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Isabella Roman',
            'phone' => '03001234567',
            'email' => 'isabella@example.com',
            'province' => 'Punjab',
            'city' => 'Lahore',
            'street' => '12 Rose Avenue',
            'postal_code' => '54000',
        ], $overrides);
    }

    protected function order(User $user, float $price, int $quantity = 1, string $payment = 'cod'): array
    {
        [$product, $variant] = $this->productAt($price);

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID, $quantity: Int!) {
                AddToCart(product_id: $product, product_variant_id: $variant, quantity: $quantity) {
                    items_count
                }
            }
        ', [
            'product' => (string) $product->id,
            'variant' => (string) $variant->id,
            'quantity' => $quantity,
        ])->assertJsonMissingPath('errors');

        return $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) {
                        subtotal
                        shipping_amount
                        cod_fee
                        total_amount
                    }
                }
            ', ['address' => $this->address(['payment_method' => $payment])])
            ->json('data.PlaceOrder') ?? [];
    }

    public function test_the_shipping_fee_is_publicly_readable(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            query { ShopSettings { shipping_fee free_shipping_threshold cod_fee currency_symbol } }
        ')
            ->assertJsonPath('data.ShopSettings.currency_symbol', 'Rs')
            ->assertJsonPath('data.ShopSettings.shipping_fee', 250)
            ->assertJsonPath('data.ShopSettings.free_shipping_threshold', 5000);
    }

    public function test_shipping_is_charged_below_the_threshold(): void
    {
        $order = $this->order(User::factory()->create(), 3000);

        $this->assertEquals(3000, $order['subtotal']);
        $this->assertEquals(250, $order['shipping_amount']);
        // 3000 + 250 shipping + 100 COD handling
        $this->assertEquals(3350, $order['total_amount']);
    }

    public function test_orders_over_the_threshold_ship_for_free(): void
    {
        $order = $this->order(User::factory()->create(), 6000);

        $this->assertEquals(6000, $order['subtotal']);
        $this->assertEquals(0, $order['shipping_amount']);
        $this->assertEquals(6100, $order['total_amount']);
    }

    public function test_bank_transfer_carries_no_cod_fee(): void
    {
        $order = $this->order(User::factory()->create(), 6000, 1, Order::PAYMENT_BANK);

        $this->assertEquals(0, $order['cod_fee']);
        $this->assertEquals(6000, $order['total_amount']);
    }

    public function test_an_admin_can_change_the_shipping_fee(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { UpdateShopSettings(shipping_fee: 400, free_shipping_threshold: 10000) {
                    shipping_fee free_shipping_threshold
                } }
            ')
            ->assertJsonPath('data.UpdateShopSettings.shipping_fee', 400)
            ->assertJsonPath('data.UpdateShopSettings.free_shipping_threshold', 10000);

        $order = $this->order(User::factory()->create(), 3000);

        $this->assertEquals(400, $order['shipping_amount']);
    }

    public function test_setting_the_threshold_to_zero_always_charges_the_fee(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { UpdateShopSettings(shipping_fee: 250, free_shipping_threshold: 0) {
                    shipping_fee
                } }
            ')
            ->assertJsonMissingPath('errors');

        $order = $this->order(User::factory()->create(), 9000);

        $this->assertEquals(250, $order['shipping_amount']);
    }

    public function test_a_negative_shipping_fee_is_rejected(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { UpdateShopSettings(shipping_fee: -5) { shipping_fee } }
            ')
            ->assertJsonPath('errors.0.message', 'Validation failed for the field [UpdateShopSettings].')
            ->assertJsonPath('errors.0.extensions.validation.shipping_fee.0', function (string $message): bool {
                return str_contains($message, 'at least 0');
            });
    }

    public function test_a_regular_user_cannot_change_the_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation { UpdateShopSettings(shipping_fee: 1) { shipping_fee } }
            ')
            ->assertJsonPath('errors.0.message', function (string $message): bool {
                return str_contains($message, 'not allowed');
            });
    }

    public function test_a_disabled_payment_method_is_refused(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { UpdateShopSettings(cod_enabled: false) { cod_enabled } }
            ')
            ->assertJsonPath('data.UpdateShopSettings.cod_enabled', false);

        $user = User::factory()->create();
        [$product, $variant] = $this->productAt(3000);

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID) {
                AddToCart(product_id: $product, product_variant_id: $variant) { items_count }
            }
        ', ['product' => (string) $product->id, 'variant' => (string) $variant->id]);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address(['payment_method' => 'cod'])])
            ->assertGraphQLErrorMessage('That payment method is currently unavailable.');
    }

    public function test_an_order_outside_pakistan_is_rejected(): void
    {
        $user = User::factory()->create();
        [$product, $variant] = $this->productAt(3000);

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID) {
                AddToCart(product_id: $product, product_variant_id: $variant) { items_count }
            }
        ', ['product' => (string) $product->id, 'variant' => (string) $variant->id]);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address(['province' => 'California'])])
            ->assertGraphQLErrorMessage('Please choose a valid province.');
    }

    public function test_a_city_must_belong_to_the_chosen_province(): void
    {
        $user = User::factory()->create();
        [$product, $variant] = $this->productAt(3000);

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID) {
                AddToCart(product_id: $product, product_variant_id: $variant) { items_count }
            }
        ', ['product' => (string) $product->id, 'variant' => (string) $variant->id]);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) { PlaceOrder(address: $address) { id } }
            ', ['address' => $this->address(['province' => 'Sindh', 'city' => 'Lahore'])])
            ->assertGraphQLErrorMessage('Please choose a city inside the selected province.');
    }
}
