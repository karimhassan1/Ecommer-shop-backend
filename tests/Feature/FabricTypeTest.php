<?php

namespace Tests\Feature;

use App\Models\FabricType;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Fabric types: the managed list behind the "Fabric Type" filter.
 *
 * The important rule is that a fabric is offered the moment it is created, not
 * only once some product is made of it — that is what makes the product form's
 * picker work like the brand and category pickers.
 */
class FabricTypeTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_an_admin_can_create_a_fabric_type(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveFabricType(name: "Khaddar") {
                        id name slug is_active product_count
                    }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SaveFabricType.name', 'Khaddar')
            ->assertJsonPath('data.SaveFabricType.slug', 'khaddar')
            ->assertJsonPath('data.SaveFabricType.is_active', true)
            ->assertJsonPath('data.SaveFabricType.product_count', 0);
    }

    public function test_a_duplicate_fabric_name_is_rejected(): void
    {
        FabricType::create(['name' => 'Cotton']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveFabricType(name: "cotton") { id } }
            ')
            ->assertGraphQLErrorMessage('A fabric type with that name already exists.');
    }

    public function test_the_picker_lists_a_fabric_the_moment_it_is_created(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveFabricType(name: "Karandi") { id } }
            ')
            ->assertJsonMissingPath('errors');

        // This is the exact document the product form's picker sends.
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                query FabricTypes {
                    AdminFabricTypes { id name }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.AdminFabricTypes.0.name', 'Karandi');
    }

    public function test_a_seller_is_offered_the_whole_active_list_including_unused_fabrics(): void
    {
        $seller = $this->staffUser(RoleSeeder::SELLER);
        $cotton = FabricType::create(['name' => 'Cotton']);

        $this->clothingProduct(['seller_id' => $seller->id, 'fabric_type_id' => $cotton->id]);

        // Neither of these is used by any product: both must still be pickable
        // on the product form, which is what used to fail for a seller.
        FabricType::create(['name' => 'Boski']);
        FabricType::create(['name' => 'Khaddar']);

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                query FabricTypes {
                    AdminFabricTypes { name product_count }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(3, 'data.AdminFabricTypes')
            ->assertJsonPath('data.AdminFabricTypes.0.name', 'Boski')
            ->assertJsonPath('data.AdminFabricTypes.0.product_count', 0)
            ->assertJsonPath('data.AdminFabricTypes.1.name', 'Cotton')
            ->assertJsonPath('data.AdminFabricTypes.1.product_count', 1)
            ->assertJsonPath('data.AdminFabricTypes.2.name', 'Khaddar');
    }

    public function test_a_seller_count_only_counts_their_own_products(): void
    {
        $seller = $this->staffUser(RoleSeeder::SELLER);
        $otherSeller = $this->staffUser(RoleSeeder::SELLER);
        $cotton = FabricType::create(['name' => 'Cotton']);

        $this->clothingProduct(['seller_id' => $otherSeller->id, 'fabric_type_id' => $cotton->id]);

        $this->actingAs($seller)
            ->graphQL(/** @lang GraphQL */ '
                query AdminFabricTypes {
                    AdminFabricTypes { name product_count }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.AdminFabricTypes.0.name', 'Cotton')
            ->assertJsonPath('data.AdminFabricTypes.0.product_count', 0);
    }

    public function test_a_retired_fabric_is_not_offered(): void
    {
        FabricType::create(['name' => 'Cotton']);
        FabricType::create(['name' => 'Wool', 'is_active' => false]);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                query AdminFabricTypes {
                    AdminFabricTypes { name is_active }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(1, 'data.AdminFabricTypes')
            ->assertJsonPath('data.AdminFabricTypes.0.name', 'Cotton')
            ->assertJsonPath('data.AdminFabricTypes.0.is_active', true);
    }

    public function test_the_admin_list_can_be_searched(): void
    {
        FabricType::create(['name' => 'Cotton']);
        FabricType::create(['name' => 'Khaddar']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                query AdminFabricTypes($search: String) {
                    AdminFabricTypes(search: $search) { name }
                }
            ', ['search' => 'khad'])
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(1, 'data.AdminFabricTypes')
            ->assertJsonPath('data.AdminFabricTypes.0.name', 'Khaddar');
    }

    public function test_a_product_carries_its_fabric_type_to_the_product_page(): void
    {
        $cotton = FabricType::create(['name' => 'Cotton']);
        $product = $this->clothingProduct(['fabric_type_id' => $cotton->id]);

        // These are the fields ProductDetail.vue renders.
        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID) {
                product(id: $id) {
                    fabric_type_id
                    fabricType { name slug }
                }
            }
        ', ['id' => (string) $product->id])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.product.fabric_type_id', (string) $cotton->id)
            ->assertJsonPath('data.product.fabricType.name', 'Cotton')
            ->assertJsonPath('data.product.fabricType.slug', 'cotton');
    }

    public function test_a_perfume_never_keeps_a_fabric_type(): void
    {
        $cotton = FabricType::create(['name' => 'Cotton']);

        $perfume = $this->perfumeProduct(['fabric_type_id' => $cotton->id]);

        $this->assertNull($perfume->fresh()->fabric_type_id);
        $this->assertSame(0, Product::query()->whereNotNull('fabric_type_id')->count());
    }

    public function test_deleting_a_fabric_type_keeps_its_products(): void
    {
        $cotton = FabricType::create(['name' => 'Cotton']);
        $product = $this->clothingProduct(['fabric_type_id' => $cotton->id]);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { DeleteFabricType(id: $id) { message deleted_id } }
            ', ['id' => (string) $cotton->id])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.DeleteFabricType.message', 'Fabric type deleted successfully.');

        $this->assertDatabaseMissing('fabric_types', ['id' => $cotton->id]);
        // The product is kept; it is simply unclassified again.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertNull($product->fresh()->fabric_type_id);
    }

    public function test_a_customer_cannot_create_a_fabric_type(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveFabricType(name: "Sneaky") { id } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_guests_cannot_list_fabric_types(): void
    {
        FabricType::create(['name' => 'Cotton']);

        $this->graphQL(/** @lang GraphQL */ '
            query { AdminFabricTypes { id name } }
        ')->assertJsonPath('errors.0.message', function (string $message): bool {
            return str_contains($message, 'Unauthenticated')
                || str_contains($message, 'not allowed');
        });
    }
}
