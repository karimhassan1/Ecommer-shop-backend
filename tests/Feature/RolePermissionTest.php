<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Role based access for the catalog.
 *
 * The catalog manager may build the catalogue but must never touch orders; the
 * support role may read products but must not change the catalogue; a plain
 * customer has no staff access at all.
 */
class RolePermissionTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    /** A minimal valid variant payload for the signed in test. */
    protected function variantPayload(): array
    {
        $ids = $this->masterIds();

        return [[
            'volume_id' => $ids['thirty'],
            'price' => 4500,
            'stock' => 3,
            'is_default' => true,
            'images' => [['url' => 'products/role.jpg']],
        ]];
    }

    protected function createMutation(): string
    {
        return <<<'GRAPHQL'
            mutation ($v: [VariantInput!]!) {
                CreateProduct(type: "perfume", name: "Role Test Oud", variants: $v) {
                    id name total_stock
                }
            }
        GRAPHQL;
    }

    public function test_the_catalog_role_can_manage_products_but_not_orders(): void
    {
        $catalog = $this->staffUser(RoleSeeder::CATALOG);

        $this->actingAs($catalog)
            ->graphQL($this->createMutation(), ['v' => $this->variantPayload()])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.CreateProduct.total_stock', 3);

        $this->actingAs($catalog)
            ->graphQL(/** @lang GraphQL */ 'query { orders(first: 5) { data { id } } }')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_the_support_role_can_read_but_not_edit_the_catalog(): void
    {
        $support = $this->staffUser(RoleSeeder::SUPPORT);
        $product = $this->perfumeProduct();

        $this->actingAs($support)
            ->graphQL(/** @lang GraphQL */ '
                query ($id: ID) { product(id: $id) { name variants { label } } }
            ', ['id' => (string) $product->id])
            ->assertJsonPath('data.product.name', 'Rose Oud Eau de Parfum');

        $this->actingAs($support)
            ->graphQL($this->createMutation(), ['v' => $this->variantPayload()])
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');

        $this->assertSame(1, Product::count());
    }

    public function test_a_plain_customer_has_no_staff_access(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $this->seedPermissions();

        foreach ([
            'query { orders(first: 5) { data { id } } }',
            'query { users(first: 5) { data { id } } }',
            'query { AdminDashboard { products } }',
        ] as $query) {
            $this->actingAs($customer)
                ->graphQL($query)
                ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
        }
    }

    public function test_a_super_admin_can_do_everything(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->graphQL($this->createMutation(), ['v' => $this->variantPayload()])
            ->assertJsonMissingPath('errors');

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ 'query { AdminDashboard { products orders } }')
            ->assertJsonPath('data.AdminDashboard.products', 1);
    }

    public function test_an_admin_can_create_a_custom_role_and_grant_it(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    CreateRole(name: "catalog-viewer", permissions: ["product.view"]) {
                        id name permissions
                    }
                }
            ')
            ->assertJsonPath('data.CreateRole.name', 'catalog-viewer')
            ->assertJsonPath('data.CreateRole.permissions', function (array $permissions): bool {
                return in_array('product.view', $permissions, true);
            });
    }
}
