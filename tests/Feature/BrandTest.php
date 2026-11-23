<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Brands: full CRUD from the admin panel, and the fact that a product's brand
 * is optional (a product may carry one, several, or none at all).
 */
class BrandTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_an_admin_can_create_a_brand(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveBrand(name: "Atelier Noir") { id name slug product_count }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SaveBrand.name', 'Atelier Noir')
            ->assertJsonPath('data.SaveBrand.slug', 'atelier-noir')
            ->assertJsonPath('data.SaveBrand.product_count', 0);
    }

    public function test_a_duplicate_brand_name_is_rejected(): void
    {
        Brand::create(['name' => 'Rosera Bloom']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveBrand(name: "rosera bloom") { id } }
            ')
            ->assertGraphQLErrorMessage('A brand with that name already exists.');
    }

    public function test_a_brand_can_be_renamed_and_its_slug_follows(): void
    {
        $brand = Brand::create(['name' => 'Old Label']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { SaveBrand(id: $id, name: "New Label") { name slug } }
            ', ['id' => (string) $brand->id])
            ->assertJsonPath('data.SaveBrand.name', 'New Label')
            ->assertJsonPath('data.SaveBrand.slug', 'new-label');
    }

    public function test_a_second_brand_with_a_similar_name_gets_a_distinct_slug(): void
    {
        // "Pret" and "Pret 2" must not collide on the unique slug column.
        Brand::create(['name' => 'Pret']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveBrand(name: "Pret 2") { slug } }
            ')
            ->assertJsonPath('data.SaveBrand.slug', 'pret-2');
    }

    public function test_deleting_a_brand_keeps_its_products(): void
    {
        $brand = Brand::create(['name' => 'Atelier Noir']);
        $product = $this->clothingProduct();
        $product->brands()->attach($brand->id);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { DeleteBrand(id: $id) { message deleted_id } }
            ', ['id' => (string) $brand->id])
            ->assertJsonPath('data.DeleteBrand.message', 'Brand deleted successfully.');

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        // The product is kept; it simply has no brand any more.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame(0, $product->fresh()->brands()->count());
    }

    public function test_the_admin_brand_list_reports_product_counts(): void
    {
        $brand = Brand::create(['name' => 'Rosera Bloom']);
        $product = $this->clothingProduct();
        $product->brands()->attach($brand->id);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                query { AdminBrands { name product_count } }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.AdminBrands.0.name', 'Rosera Bloom')
            ->assertJsonPath('data.AdminBrands.0.product_count', 1);
    }

    public function test_a_product_can_be_saved_without_a_brand(): void
    {
        $product = $this->clothingProduct();

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID) {
                product(id: $id) { name brands { id } }
            }
        ', ['id' => (string) $product->id])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.product.name', $product->name)
            ->assertJsonCount(0, 'data.product.brands');
    }

    public function test_a_product_can_be_created_with_a_brand(): void
    {
        $brand = Brand::create(['name' => 'D&J Collective']);
        $ids = $this->masterIds();

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($brandId: ID!, $variants: [VariantInput!]!) {
                    CreateProduct(
                        type: "clothing"
                        name: "Linen Kurta"
                        description: "A lightweight linen kurta for warm afternoons."
                        brand_ids: [$brandId]
                        variants: $variants
                    ) {
                        name
                        brands { name slug }
                    }
                }
            ', [
                'brandId' => (string) $brand->id,
                'variants' => [[
                    'color_id' => (string) $ids['pink'],
                    'size_id' => (string) $ids['medium'],
                    'price' => 4500,
                    'stock' => 5,
                    'is_default' => true,
                    'images' => [
                        ['url' => 'products/linen-kurta.jpg', 'is_primary' => true],
                    ],
                ]],
            ])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.CreateProduct.brands.0.name', 'D&J Collective')
            ->assertJsonPath('data.CreateProduct.brands.0.slug', 'dj-collective');
    }

    public function test_updating_a_product_replaces_its_brands(): void
    {
        $first = Brand::create(['name' => 'First Label']);
        $second = Brand::create(['name' => 'Second Label']);

        $product = $this->clothingProduct();
        $product->brands()->attach($first->id);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!, $brandId: ID!) {
                    UpdateProduct(id: $id, brand_ids: [$brandId]) {
                        brands { name }
                    }
                }
            ', ['id' => (string) $product->id, 'brandId' => (string) $second->id])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.UpdateProduct.brands.0.name', 'Second Label');

        $this->assertSame(1, $product->fresh()->brands()->count());
    }

    public function test_sending_an_empty_brand_list_clears_the_brands(): void
    {
        $brand = Brand::create(['name' => 'Atelier Noir']);
        $product = $this->clothingProduct();
        $product->brands()->attach($brand->id);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { UpdateProduct(id: $id, brand_ids: []) { brands { id } } }
            ', ['id' => (string) $product->id])
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(0, 'data.UpdateProduct.brands');

        $this->assertSame(0, $product->fresh()->brands()->count());
    }

    public function test_guests_cannot_list_admin_brands(): void
    {
        Brand::create(['name' => 'Rosera Bloom']);

        $this->graphQL(/** @lang GraphQL */ '
            query { AdminBrands { id name } }
        ')->assertJsonPath('errors.0.message', function (string $message): bool {
            return str_contains($message, 'Unauthenticated')
                || str_contains($message, 'not allowed');
        });
    }

    public function test_a_regular_customer_cannot_manage_brands(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveBrand(name: "Sneaky") { id } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_a_support_role_without_brand_permissions_is_refused(): void
    {
        // The support role reads the catalog but may not add labels.
        $this->actingAs($this->staffUser(RoleSeeder::SUPPORT))
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveBrand(name: "Sneaky") { id } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }
}
