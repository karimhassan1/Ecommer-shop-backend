<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Public reads, guarded writes.
 *
 * The storefront works for guests; every catalog or customer write needs a
 * signed in administrator holding the matching permission.
 */
class AuthorizationTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_the_public_catalog_is_readable_by_guests(): void
    {
        $this->clothingProduct();

        $this->graphQL(/** @lang GraphQL */ '
            query {
                products(first: 5) {
                    data { id name in_stock lowest_price primary_image }
                    paginatorInfo { total }
                }
            }
        ')
            ->assertJsonPath('data.products.paginatorInfo.total', 1)
            ->assertJsonPath('data.products.data.0.name', 'Silk Bloom Midi Dress')
            ->assertJsonPath('data.products.data.0.in_stock', true)
            ->assertJsonPath('data.products.data.0.lowest_price', 6500);
    }

    public function test_the_storefront_product_page_exposes_the_default_variant(): void
    {
        $product = $this->perfumeProduct();

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID) {
                product(id: $id) {
                    name
                    default_variant_id
                    default_variant { label price final_price image_urls }
                    volumes { label in_stock }
                }
            }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.product.default_variant.label', '30 ml')
            ->assertJsonPath('data.product.volumes.0.label', '30 ml')
            ->assertJsonPath('data.product.default_variant.final_price', 8500);
    }

    public function test_guests_cannot_list_users(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            query { users(first: 5) { data { id email } } }
        ')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_regular_users_cannot_list_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                query { users(first: 5) { data { id email } } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_regular_users_cannot_create_products(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    CreateProduct(
                        type: "perfume"
                        name: "Sneaky Oud"
                        variants: [{
                            volume_id: 1
                            price: 1000
                            images: [{ url: "a.jpg" }]
                        }]
                    ) { id }
                }
            ')
            ->assertJsonPath('errors.0.message', function (string $message): bool {
                return str_contains($message, 'not allowed') || str_contains($message, 'Unauthenticated');
            });

        $this->assertDatabaseCount('products', 0);
    }

    public function test_admins_can_manage_the_catalog(): void
    {
        $admin = $this->adminUser();
        $ids = $this->masterIds();

        $productId = $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($v: [VariantInput!]!) {
                    CreateProduct(type: "perfume", name: "Admin Oud", variants: $v) { id }
                }
            ', ['v' => [[
                'volume_id' => $ids['thirty'],
                'price' => 4500,
                'stock' => 3,
                'is_default' => true,
                'images' => [['url' => 'admin.jpg']],
            ]]])
            ->assertJsonMissingPath('errors')
            ->json('data.CreateProduct.id');

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) {
                    UpdateProduct(id: $id, is_active: false) { id is_active }
                }
            ', ['id' => (string) $productId])
            ->assertJsonPath('data.UpdateProduct.is_active', false);
    }

    public function test_regular_users_cannot_view_the_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ 'query { AdminDashboard { products } }')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_admins_can_see_private_contact_messages(): void
    {
        ContactMessage::create([
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
            'subject' => 'Question',
            'message' => 'Do you ship to Multan?',
        ]);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                query { contact_messages(first: 5) { data { subject } } }
            ')
            ->assertJsonPath('data.contact_messages.data.0.subject', 'Question');
    }
}
