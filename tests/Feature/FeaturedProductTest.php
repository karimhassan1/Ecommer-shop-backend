<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * "Show on the home page" is a merchandising flag, not a visibility rule.
 *
 * Turning it on promotes a product to the home page rail; turning it off leaves
 * the product in the shop listing untouched. `is_active` stays the separate
 * "visible in the store at all" switch.
 */
class FeaturedProductTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function browseMutation(): string
    {
        return <<<'GRAPHQL'
            query Browse($featured_only: Boolean!) {
                BrowseProducts(first: 20, featured_only: $featured_only) {
                    data { id name is_featured is_active }
                    paginatorInfo { total }
                }
            }
        GRAPHQL;
    }

    public function test_only_featured_products_are_returned_when_asked(): void
    {
        $featured = $this->clothingProduct(['name' => 'Hero Dress', 'is_featured' => true]);
        $this->perfumeProduct(['name' => 'Shop Only Scent', 'is_featured' => false]);

        $this->graphQL($this->browseMutation(), ['featured_only' => true])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.BrowseProducts.paginatorInfo.total', 1)
            ->assertJsonPath('data.BrowseProducts.data.0.id', (string) $featured->id)
            ->assertJsonPath('data.BrowseProducts.data.0.is_featured', true);
    }

    public function test_the_shop_listing_still_shows_everything(): void
    {
        $this->clothingProduct(['name' => 'Hero Dress', 'is_featured' => true]);
        $this->perfumeProduct(['name' => 'Shop Only Scent', 'is_featured' => false]);

        $this->graphQL($this->browseMutation(), ['featured_only' => false])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.BrowseProducts.paginatorInfo.total', 2);
    }

    public function test_an_inactive_product_never_reaches_the_home_page(): void
    {
        $this->clothingProduct([
            'name' => 'Archived Hero',
            'is_featured' => true,
            'is_active' => false,
        ]);

        $this->graphQL($this->browseMutation(), ['featured_only' => true])
            ->assertJsonPath('data.BrowseProducts.paginatorInfo.total', 0);
    }

    public function test_an_admin_can_turn_the_home_page_flag_on_and_off(): void
    {
        $product = $this->perfumeProduct(['is_featured' => false]);

        $mutation = <<<'GRAPHQL'
            mutation ($id: ID!, $is_featured: Boolean) {
                UpdateProduct(id: $id, is_featured: $is_featured) {
                    id
                    is_featured
                    name
                }
            }
        GRAPHQL;

        $this->actingAs($this->adminUser())
            ->graphQL($mutation, ['id' => (string) $product->id, 'is_featured' => true])
            ->assertJsonPath('data.UpdateProduct.is_featured', true);

        $this->assertTrue($product->fresh()->is_featured);

        $this->actingAs($this->adminUser())
            ->graphQL($mutation, ['id' => (string) $product->id, 'is_featured' => false])
            ->assertJsonPath('data.UpdateProduct.is_featured', false);

        $this->assertFalse($product->fresh()->is_featured);
    }

    public function test_a_product_is_not_featured_unless_it_says_so(): void
    {
        $product = Product::create([
            'type' => Product::TYPE_CLOTHING,
            'name' => 'Plain Product',
            'is_active' => true,
        ]);

        $this->assertFalse($product->fresh()->is_featured);
    }
}
