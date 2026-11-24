<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\TestCase;

/**
 * Provinces and cities come from a third party geo API, merged over the list
 * shipped with the shop.
 *
 * The checkout asks for the live list so a customer can pick a town nobody
 * typed into `config/pakistan.php`, while an outage has to degrade to the
 * shipped list rather than empty selects.
 */
class GeoLocationsTest extends TestCase
{
    use CreatesCatalog;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The shipped configured value is off in phpunit.xml; these tests are
        // about the provider path, so they switch it back on per test.
        config(['services.geo.enabled' => true, 'services.geo.timeout' => 2]);

        Cache::forget(GeoService::CACHE_KEY);
    }

    /** A provider response with two states and their cities. */
    protected function fakeProvider(array $cities = []): void
    {
        Http::fake([
            '*/countries/states' => Http::response([
                'error' => false,
                'data' => [
                    'states' => [
                        ['name' => 'Punjab'],
                        ['name' => 'Sindh'],
                    ],
                ],
            ]),
            '*/countries/state/cities' => Http::response([
                'error' => false,
                'data' => $cities,
            ]),
        ]);
    }

    public function test_the_checkout_is_offered_the_provider_cities(): void
    {
        $this->fakeProvider(['Lahore', 'Chunian', 'Renala Khurd']);

        $response = $this->graphQL(/** @lang GraphQL */ '
            query { PakistanLocations { name cities } }
        ')->assertJsonMissingPath('errors');

        $punjab = collect($response->json('data.PakistanLocations'))->firstWhere('name', 'Punjab');

        $this->assertNotNull($punjab);
        // A town that only the provider knows proves the live list was used.
        $this->assertContains('Chunian', $punjab['cities']);
        // And the shipped list is still there, so a sparse provider response
        // cannot remove a deliverable city.
        $this->assertContains('Multan', $punjab['cities']);
    }

    public function test_a_provider_outage_falls_back_to_the_shipped_list(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            query { PakistanLocations { name cities } }
        ')->assertJsonMissingPath('errors');

        $punjab = collect($response->json('data.PakistanLocations'))->firstWhere('name', 'Punjab');

        $this->assertNotNull($punjab);
        $this->assertContains('Lahore', $punjab['cities']);
    }

    public function test_one_province_can_be_requested_on_its_own(): void
    {
        $this->fakeProvider(['Lahore']);

        $this->graphQL(/** @lang GraphQL */ '
            query { PakistanLocations(province: "Sindh") { name } }
        ')
            ->assertJsonPath('data.PakistanLocations.0.name', 'Sindh')
            ->assertJsonCount(1, 'data.PakistanLocations');
    }

    public function test_an_order_is_validated_against_the_live_city_list(): void
    {
        // Only the provider knows this town, so a successful order proves the
        // validation used the same merged catalogue the shop is offered.
        $this->fakeProvider(['Lahore', 'Chunian']);

        \Illuminate\Support\Facades\Mail::fake();

        $buyer = User::factory()->create();
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->actingAs($buyer)->graphQL(/** @lang GraphQL */ '
            mutation ($product: ID!, $variant: ID) {
                AddToCart(product_id: $product, product_variant_id: $variant, quantity: 1) { items_count }
            }
        ', ['product' => (string) $product->id, 'variant' => (string) $variant->id])
            ->assertJsonMissingPath('errors');

        $this->actingAs($buyer)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($address: CheckoutAddressInput!) {
                    PlaceOrder(address: $address) { id province city }
                }
            ', ['address' => [
                'name' => 'Ayesha Khan',
                'phone' => '03001234567',
                'email' => 'ayesha@example.com',
                'province' => 'Punjab',
                'city' => 'Chunian',
                'street' => '12 Rose Avenue',
            ]])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.PlaceOrder.city', 'Chunian');
    }
}
