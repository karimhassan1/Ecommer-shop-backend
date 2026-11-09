<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\Size;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * The shop wide master lists that every product is built from.
 *
 * Adding a colour / size / volume once makes it available on every product, and
 * the admin form loads all three lists in a single request.
 */
class VariantMasterTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_the_master_lists_are_available_in_one_request(): void
    {
        $this->seedVariantMasters();

        $response = $this->graphQL(/** @lang GraphQL */ '
            query {
                VariantMasters {
                    colors { id name hex sort_order is_active }
                    sizes { id name label sort_order is_active }
                    volumes { id label ml sort_order is_active }
                }
            }
        ');

        $response
            ->assertJsonPath('data.VariantMasters.colors.0.name', 'Rose Pink')
            ->assertJsonPath('data.VariantMasters.colors.0.hex', '#e8a0bf')
            ->assertJsonPath('data.VariantMasters.sizes.0.name', 'XS')
            ->assertJsonPath('data.VariantMasters.sizes.0.label', 'XS')
            ->assertJsonPath('data.VariantMasters.volumes.0.label', '6 ml')
            ->assertJsonPath('data.VariantMasters.volumes.0.ml', 6);

        $this->assertCount(10, $response->json('data.VariantMasters.colors'));
        $this->assertCount(6, $response->json('data.VariantMasters.sizes'));
        $this->assertCount(5, $response->json('data.VariantMasters.volumes'));
    }

    public function test_an_admin_can_add_a_master_value_that_is_then_usable(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    SaveVariantMaster(kind: "color", label: "Lilac", hex: "#c8a2c8") {
                        id kind label hex
                    }
                }
            ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SaveVariantMaster.kind', 'color')
            ->assertJsonPath('data.SaveVariantMaster.label', 'Lilac')
            ->assertJsonPath('data.SaveVariantMaster.hex', '#c8a2c8');

        $this->assertNotNull(Color::where('name', 'Lilac')->first());
    }

    public function test_a_duplicate_master_value_is_rejected(): void
    {
        $this->seedVariantMasters();

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveVariantMaster(kind: "size", label: "M") { id } }
            ')
            ->assertGraphQLErrorMessage('That value already exists in the list.');
    }

    public function test_only_staff_can_change_the_master_lists(): void
    {
        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation { SaveVariantMaster(kind: "size", label: "XXXL") { id } }
            ')
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');

        $this->assertNull(Size::where('name', 'XXXL')->first());
    }

    public function test_an_unknown_master_id_is_rejected(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation {
                    CreateProduct(
                        type: "perfume"
                        name: "Ghost Volume"
                        variants: [{
                            volume_id: 999999
                            price: 3000
                            stock: 2
                            images: [{ url: "products/a.jpg" }]
                        }]
                    ) { id }
                }
            ')
            ->assertGraphQLErrorMessage('That volume is no longer available.');
    }

    public function test_each_variant_keeps_its_own_price_and_gallery(): void
    {
        $product = $this->clothingProduct();
        $medium = $this->defaultVariant($product);

        $large = $this->addVariant($product, [
            'color_id' => $medium->color_id,
            'size_id' => $this->sizeModel('L')->id,
            'price' => 7900,
            'stock' => 2,
        ]);

        $this->addImage($large, 'products/large-b.jpg');

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID) { product(id: $id) { variants { label price image_urls } } }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.product.variants.0.price', 6500)
            ->assertJsonPath('data.product.variants.1.price', 7900);

        $this->assertCount(2, $large->fresh()->image_urls);
    }

    public function test_products_can_be_filtered_by_type_and_category(): void
    {
        $clothing = Category::create(['name' => 'Pret', 'type' => 'clothing']);
        $perfume = Category::create(['name' => 'Attar', 'type' => 'perfume']);

        $this->clothingProduct()->categories()->attach($clothing->id);
        $this->perfumeProduct(['name' => 'Oud Attar'])->categories()->attach($perfume->id);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                BrowseProducts(type: "perfume", first: 10) {
                    data { name }
                    paginatorInfo { total }
                }
            }
        ')
            ->assertJsonPath('data.BrowseProducts.paginatorInfo.total', 1)
            ->assertJsonPath('data.BrowseProducts.data.0.name', 'Oud Attar');

        $this->graphQL(/** @lang GraphQL */ '
            query { BrowseProducts(category_slug: "pret", first: 10) { data { name } } }
        ')->assertJsonPath('data.BrowseProducts.data.0.name', 'Silk Bloom Midi Dress');
    }

    public function test_the_shop_can_sort_by_the_cheapest_variant_price(): void
    {
        $cheap = $this->perfumeProduct(['name' => 'Cheap Attar']);
        $this->defaultVariant($cheap)->forceFill(['price' => 900])->save();

        $pricey = $this->perfumeProduct(['name' => 'Pricey Oud']);
        $this->defaultVariant($pricey)->forceFill(['price' => 9900])->save();

        $this->graphQL(/** @lang GraphQL */ '
            query { BrowseProducts(order_by: "price_asc", first: 10) { data { name lowest_price } } }
        ')
            ->assertJsonPath('data.BrowseProducts.data.0.name', 'Cheap Attar')
            ->assertJsonPath('data.BrowseProducts.data.0.lowest_price', 900)
            ->assertJsonPath('data.BrowseProducts.data.1.name', 'Pricey Oud');
    }

    public function test_price_and_stock_filters_use_the_variants(): void
    {
        $cheap = $this->perfumeProduct(['name' => 'Cheap Attar']);
        $this->defaultVariant($cheap)->forceFill(['price' => 900, 'stock' => 4])->save();

        $pricey = $this->perfumeProduct(['name' => 'Pricey Oud']);
        $this->defaultVariant($pricey)->forceFill(['price' => 9900, 'stock' => 4])->save();

        $this->graphQL(/** @lang GraphQL */ '
            query { BrowseProducts(min_price: 5000, first: 10) { data { name } } }
        ')
            ->assertJsonCount(1, 'data.BrowseProducts.data')
            ->assertJsonPath('data.BrowseProducts.data.0.name', 'Pricey Oud');

        // Only the in-stock product is returned.
        $this->defaultVariant($pricey)->forceFill(['stock' => 0])->save();

        $this->graphQL(/** @lang GraphQL */ '
            query { BrowseProducts(in_stock_only: true, first: 10) { data { name } } }
        ')
            ->assertJsonCount(1, 'data.BrowseProducts.data')
            ->assertJsonPath('data.BrowseProducts.data.0.name', 'Cheap Attar');
    }

    public function test_the_volume_list_is_ordered_lightest_first(): void
    {
        $product = $this->perfumeProduct();

        $this->addVariant($product, [
            'volume_id' => Volume::where('label', '6 ml')->value('id'),
            'price' => 1500,
            'stock' => 4,
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID) { product(id: $id) { volumes { label ml } } }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.product.volumes.0.label', '6 ml')
            ->assertJsonPath('data.product.volumes.1.label', '30 ml');
    }
}
