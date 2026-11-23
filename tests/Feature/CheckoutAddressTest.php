<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * The checkout address form: every province of Pakistan is offered, the city
 * list follows the province, and the API refuses a city that does not belong to
 * its province instead of silently saving a parcel nobody can deliver.
 */
class CheckoutAddressTest extends TestCase
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

    protected function address(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ayesha Khan',
            'phone' => '03001234567',
            'email' => 'ayesha@example.com',
            'province' => 'Punjab',
            'city' => 'Lahore',
            'street' => '12 Rose Avenue',
            'postal_code' => '54000',
        ], $overrides);
    }

    protected function cartWithOneLine(): User
    {
        $user = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID) {
                AddToCart(product_id: $product, product_variant_id: $variant, quantity: 1) {
                    items_count
                }
            }
        ', [
            'product' => (string) $product->id,
            'variant' => (string) $variant->id,
        ])->assertJsonMissingPath('errors');

        return $user;
    }

    public function test_shop_settings_offer_every_province_with_its_cities(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            query { ShopSettings { provinces { name cities } } }
        ');

        $provinces = collect($response->json('data.ShopSettings.provinces'));
        $names = $provinces->pluck('name');

        foreach (['Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad Capital Territory', 'Gilgit-Baltistan', 'Azad Jammu and Kashmir'] as $expected) {
            $this->assertContains($expected, $names, "Missing province: {$expected}");
        }

        // The city list must be substantial for the populated provinces.
        $punjab = $provinces->firstWhere('name', 'Punjab');
        $this->assertGreaterThan(20, count($punjab['cities']));
        $this->assertContains('Lahore', $punjab['cities']);

        $sindh = $provinces->firstWhere('name', 'Sindh');
        $this->assertContains('Karachi', $sindh['cities']);
        $this->assertNotContains('Lahore', $sindh['cities']);
    }

    public function test_an_order_is_placed_with_a_valid_province_and_city(): void
    {
        $user = $this->cartWithOneLine();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) { id province city postal_code status }
                }
            ', ['address' => $this->address()])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.PlaceOrder.province', 'Punjab')
            ->assertJsonPath('data.PlaceOrder.city', 'Lahore')
            ->assertJsonPath('data.PlaceOrder.postal_code', '54000');
    }

    public function test_a_postal_code_is_optional(): void
    {
        $user = $this->cartWithOneLine();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) { id postal_code }
                }
            ', ['address' => $this->address(['postal_code' => null])])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.PlaceOrder.postal_code', null);

        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_city_from_another_province_is_rejected(): void
    {
        $user = $this->cartWithOneLine();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) { id }
                }
            ', ['address' => $this->address(['city' => 'Karachi'])])
            ->assertGraphQLErrorMessage('Please choose a city inside the selected province.');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_unknown_province_is_rejected(): void
    {
        $user = $this->cartWithOneLine();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) { id }
                }
            ', ['address' => $this->address(['province' => 'Atlantis'])])
            ->assertGraphQLErrorMessage('Please choose a valid province.');
    }

    public function test_a_malformed_phone_number_is_rejected(): void
    {
        $user = $this->cartWithOneLine();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) { id }
                }
            ', ['address' => $this->address(['phone' => '12'])])
            ->assertGraphQLErrorMessage('Please enter a valid Pakistani phone number (10 to 15 digits).');
    }

    public function test_the_province_and_city_are_stored_in_canonical_case(): void
    {
        $user = $this->cartWithOneLine();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) { id province city }
                }
            ', ['address' => $this->address([
                'province' => 'punjab',
                'city' => 'rawalpindi',
            ])])
            ->assertJsonPath('data.PlaceOrder.province', 'Punjab')
            ->assertJsonPath('data.PlaceOrder.city', 'Rawalpindi');
    }

    public function test_a_profile_cannot_store_a_city_outside_its_province(): void
    {
        $user = User::factory()->create();
        $user->cartOrCreate();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    UpdateProfile(state: "Sindh", city: "Lahore") { id }
                }
            ')
            ->assertGraphQLErrorMessage('Please choose a city inside the selected province.');
    }

    public function test_a_profile_saves_the_canonical_city_name(): void
    {
        $user = User::factory()->create();
        $user->cartOrCreate();

        $this->actingAs($user)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    UpdateProfile(state: "Sindh", city: "karachi") { id state city }
                }
            ')
            ->assertJsonPath('data.UpdateProfile.state', 'Sindh')
            ->assertJsonPath('data.UpdateProfile.city', 'Karachi');
    }

    public function test_the_shop_facets_describe_the_active_catalogue(): void
    {
        $product = $this->clothingProduct();
        $category = Category::create(['name' => 'Pret', 'type' => 'clothing']);
        $product->categories()->attach($category->id);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                ShopFilters {
                    min_price
                    max_price
                    categories { name product_count slug }
                    brands { name product_count }
                    sizes
                }
            }
        ')
            ->assertJsonPath('data.ShopFilters.min_price', 6500)
            ->assertJsonPath('data.ShopFilters.max_price', 6500)
            ->assertJsonPath('data.ShopFilters.categories.0.name', 'Pret')
            ->assertJsonPath('data.ShopFilters.categories.0.product_count', 1)
            ->assertJsonPath('data.ShopFilters.sizes.0', 'M');
    }

    public function test_products_can_be_filtered_by_category_price_and_type(): void
    {
        $clothing = $this->clothingProduct();
        $perfume = $this->perfumeProduct();
        $category = Category::create(['name' => 'Pret', 'type' => 'clothing']);
        $clothing->categories()->attach($category->id);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                byCategory: BrowseProducts(category_slug: "pret") { data { name } }
                cheap: BrowseProducts(max_price: 7000) { data { name } }
                perfume: BrowseProducts(type: "perfume") { data { name } }
            }
        ')
            ->assertJsonPath('data.byCategory.data.0.name', $clothing->name)
            ->assertJsonPath('data.cheap.data.0.name', $clothing->name)
            ->assertJsonPath('data.perfume.data.0.name', $perfume->name);
    }

    public function test_products_can_be_filtered_by_size(): void
    {
        $clothing = $this->clothingProduct(); // has an "M" variant
        $this->perfumeProduct(); // volume only, no sizes

        $this->graphQL(/** @lang GraphQL */ '
            query {
                match: BrowseProducts(sizes: ["M"]) { data { name } paginatorInfo { total } }
                none: BrowseProducts(sizes: ["XXL"]) { data { name } paginatorInfo { total } }
            }
        ')
            ->assertJsonPath('data.match.paginatorInfo.total', 1)
            ->assertJsonPath('data.match.data.0.name', $clothing->name)
            ->assertJsonPath('data.none.paginatorInfo.total', 0);
    }
}
