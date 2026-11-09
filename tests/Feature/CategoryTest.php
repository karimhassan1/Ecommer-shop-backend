<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Categories: slugs derived from the name, split by product type, and deleted
 * only when empty.
 */
class CategoryTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_an_admin_can_create_a_category(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveCategory(name: "Pret & Ready to Wear", type: "clothing") {
                        id name slug type
                    }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SaveCategory.name', 'Pret & Ready to Wear')
            ->assertJsonPath('data.SaveCategory.slug', 'pret-ready-to-wear')
            ->assertJsonPath('data.SaveCategory.type', 'clothing');
    }

    public function test_a_duplicate_name_is_rejected(): void
    {
        Category::create(['name' => 'Formal Wear', 'type' => 'clothing']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveCategory(name: "Formal Wear", type: "clothing") { id } }
            ')
            ->assertGraphQLErrorMessage('A category with a similar name already exists.');
    }

    public function test_the_slug_is_regenerated_when_the_name_changes(): void
    {
        $category = Category::create(['name' => 'Formal Wear', 'type' => 'clothing']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) {
                    SaveCategory(id: $id, name: "Evening Wear", type: "clothing") { name slug }
                }
            ', ['id' => (string) $category->id])
            ->assertJsonPath('data.SaveCategory.name', 'Evening Wear')
            ->assertJsonPath('data.SaveCategory.slug', 'evening-wear');
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = Category::create(['name' => 'Clearance', 'type' => 'clothing']);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { DeleteCategory(id: $id) { message deleted_id } }
            ', ['id' => (string) $category->id])
            ->assertJsonPath('data.DeleteCategory.message', 'Category deleted successfully.');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_category_holding_products_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Pret', 'type' => 'clothing']);
        $product = $this->clothingProduct();
        $product->categories()->attach($category->id);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { DeleteCategory(id: $id) { message } }
            ', ['id' => (string) $category->id])
            ->assertGraphQLErrorMessage(
                'Move the 1 product(s) in this category elsewhere before deleting it.'
            );
    }

    public function test_the_category_list_is_split_by_type(): void
    {
        Category::create(['name' => 'Formal Wear', 'type' => 'clothing']);
        Category::create(['name' => 'Eau de Parfum', 'type' => 'perfume']);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                clothing: CategoryList(type: "clothing") { name }
                perfume: CategoryList(type: "perfume") { name }
            }
        ')
            ->assertJsonPath('data.clothing.0.name', 'Formal Wear')
            ->assertJsonPath('data.perfume.0.name', 'Eau de Parfum');
    }

    public function test_guests_cannot_list_admin_categories(): void
    {
        Category::create(['name' => 'Pret', 'type' => 'clothing']);

        $this->graphQL(/** @lang GraphQL */ '
            query { AdminCategories { id name } }
        ')->assertJsonPath('errors.0.message', function (string $message): bool {
            return str_contains($message, 'Unauthenticated')
                || str_contains($message, 'not allowed');
        });
    }

    public function test_a_regular_customer_cannot_manage_categories(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveCategory(name: "Sneaky", type: "clothing") { id } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }
}
