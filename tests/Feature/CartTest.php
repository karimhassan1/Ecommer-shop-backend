<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\TestCase;

/**
 * Cart behaviour with variants as the unit of stock.
 *
 * Every product has variants, so a cart line always names one and the price
 * comes from the variant's `final_price` (discount applied).
 */
class CartTest extends TestCase
{
    use CreatesCatalog;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_a_user_starts_with_an_empty_cart(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                query { MyCart { items_count total_amount cartItem { id } } }
            ')
            ->assertJsonPath('data.MyCart.items_count', 0)
            ->assertJsonPath('data.MyCart.total_amount', 0);
    }

    public function test_a_user_can_add_a_variant_to_the_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($product: ID!, $variant: ID) {
                    AddToCart(product_id: $product, product_variant_id: $variant, quantity: 2) {
                        items_count
                        total_amount
                        cartItem { quantity line_total label product { name } variant { id label } }
                    }
                }
            ', ['product' => (string) $product->id, 'variant' => (string) $variant->id])
            ->assertJsonPath('data.AddToCart.items_count', 2)
            ->assertJsonPath('data.AddToCart.total_amount', 13000)
            ->assertJsonPath('data.AddToCart.cartItem.0.line_total', 13000)
            ->assertJsonPath('data.AddToCart.cartItem.0.label', 'Rose Pink / M');
    }

    public function test_the_cart_uses_the_discounted_price(): void
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);
        $variant->forceFill(['discount_price' => 5000])->save();

        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($product: ID!, $variant: ID) {
                    AddToCart(product_id: $product, product_variant_id: $variant, quantity: 2) {
                        total_amount
                        cartItem { unit_price line_total }
                    }
                }
            ', ['product' => (string) $product->id, 'variant' => (string) $variant->id])
            ->assertJsonPath('data.AddToCart.cartItem.0.unit_price', 5000)
            ->assertJsonPath('data.AddToCart.cartItem.0.line_total', 10000)
            ->assertJsonPath('data.AddToCart.total_amount', 10000);
    }

    public function test_adding_the_same_variant_merges_the_quantity(): void
    {
        $user = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        foreach ([1, 2] as $quantity) {
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
            ]);
        }

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                query { MyCart { items_count cartItem { quantity } } }
            ')
            ->assertJsonPath('data.MyCart.cartItem.0.quantity', 3);
    }

    public function test_two_sizes_of_the_same_colour_are_two_cart_lines(): void
    {
        $user = User::factory()->create();
        $product = $this->clothingProduct();
        $medium = $this->defaultVariant($product);

        $large = $this->addVariant($product, [
            'color_id' => $medium->color_id,
            'size_id' => $this->sizeModel('L')->id,
            'price' => 6500,
            'stock' => 4,
        ]);

        foreach ([$medium, $large] as $variant) {
            $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
                mutation ($product: ID!, $variant: ID) {
                    AddToCart(product_id: $product, product_variant_id: $variant, quantity: 1) { items_count }
                }
            ', ['product' => (string) $product->id, 'variant' => (string) $variant->id]);
        }

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                query { MyCart { items_count cartItem { label } } }
            ')
            ->assertJsonPath('data.MyCart.items_count', 2)
            ->assertJsonPath('data.MyCart.cartItem.1.label', 'Rose Pink / L');
    }

    public function test_a_user_cannot_add_more_than_the_variant_stock(): void
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);
        $variant->forceFill(['stock' => 2])->save();

        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($product: ID!, $variant: ID) {
                    AddToCart(product_id: $product, product_variant_id: $variant, quantity: 5) { items_count }
                }
            ', ['product' => (string) $product->id, 'variant' => (string) $variant->id])
            ->assertJsonPath('errors.0.message', function (string $message): bool {
                return str_contains($message, 'Only 2 item(s)');
            });
    }

    public function test_a_variant_must_be_chosen_before_adding_to_the_cart(): void
    {
        $product = $this->clothingProduct();

        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($product: ID!) { AddToCart(product_id: $product) { items_count } }
            ', ['product' => (string) $product->id])
            ->assertGraphQLErrorMessage('Please choose a colour and size first.');
    }

    public function test_an_inactive_product_cannot_be_added_to_the_cart(): void
    {
        $product = $this->clothingProduct(['is_active' => false]);
        $variant = $this->defaultVariant($product);

        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($product: ID!, $variant: ID) {
                    AddToCart(product_id: $product, product_variant_id: $variant) { items_count }
                }
            ', ['product' => (string) $product->id, 'variant' => (string) $variant->id])
            ->assertGraphQLErrorMessage('This product is currently unavailable.');
    }

    public function test_a_user_can_update_and_remove_a_cart_line(): void
    {
        $user = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $added = $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID) {
                AddToCart(product_id: $product, product_variant_id: $variant, quantity: 1) { cartItem { id } }
            }
        ', ['product' => (string) $product->id, 'variant' => (string) $variant->id]);

        $itemId = $added->json('data.AddToCart.cartItem.0.id');

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { UpdateCartItem(id: $id, quantity: 4) { items_count } }
            ', ['id' => (string) $itemId])
            ->assertJsonPath('data.UpdateCartItem.items_count', 4);

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { RemoveCartItem(id: $id) { items_count cartItem { id } } }
            ', ['id' => (string) $itemId])
            ->assertJsonPath('data.RemoveCartItem.items_count', 0);
    }

    public function test_a_user_cannot_touch_another_users_cart_line(): void
    {
        $owner = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $added = $this->actingAs($owner)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID) {
                AddToCart(product_id: $product, product_variant_id: $variant, quantity: 1) { cartItem { id } }
            }
        ', ['product' => (string) $product->id, 'variant' => (string) $variant->id]);

        $itemId = $added->json('data.AddToCart.cartItem.0.id');

        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { RemoveCartItem(id: $id) { items_count } }
            ', ['id' => (string) $itemId])
            ->assertGraphQLErrorMessage('This cart item could not be found.');
    }
}
