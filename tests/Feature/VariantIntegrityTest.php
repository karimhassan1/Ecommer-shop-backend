<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * The catalogue invariants hold even when a write does not go through
 * `ProductVariantService`.
 *
 * LaraQL also generates generic `createProductVariant` / `updateProductVariant` /
 * `deleteProductVariant` mutations, which write straight to the table. Without a
 * safety net those could give a product two defaults (a raw unique-index
 * violation, so a 500) or leave it with zero variants — states the product page
 * cannot render.
 */
class VariantIntegrityTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_a_second_default_releases_the_first_one(): void
    {
        $product = $this->perfumeProduct();
        $original = $this->defaultVariant($product);
        $volumeId = $this->volumeModel('6 ml')->id;

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($product_id: Int!, $volume_id: Int!) {
                    createProductVariant(input: {
                        product_id: $product_id
                        volume_id: $volume_id
                        price: 900
                        is_default: true
                    }) {
                        id
                        is_default
                    }
                }
            ', [
                'product_id' => (int) $product->id,
                'volume_id' => (int) $volumeId,
            ])
            ->assertJsonPath('data.createProductVariant.is_default', true);

        // Exactly one default, and it is the new row — not a database exception.
        $defaults = ProductVariant::where('product_id', $product->id)
            ->where('is_default', true)
            ->pluck('id')
            ->all();

        $this->assertCount(1, $defaults);
        $this->assertNotSame($original->id, $defaults[0]);
    }

    public function test_making_an_existing_variant_default_releases_the_old_one(): void
    {
        $product = $this->perfumeProduct();
        $original = $this->defaultVariant($product);

        $other = $this->addVariant($product, [
            'volume_id' => $this->volumeModel('6 ml')->id,
            'price' => 1500,
        ]);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!, $product_id: Int!, $volume_id: Int!, $price: Float!) {
                    updateProductVariant(id: $id, input: {
                        product_id: $product_id
                        volume_id: $volume_id
                        price: $price
                        is_default: true
                    }) { id is_default }
                }
            ', [
                'id' => (string) $other->id,
                'product_id' => (int) $product->id,
                'volume_id' => (int) $other->volume_id,
                'price' => 1500.0,
            ])
            ->assertJsonMissingPath('errors');

        $this->assertFalse($original->fresh()->is_default);
        $this->assertTrue($other->fresh()->is_default);
    }

    public function test_the_last_variant_of_a_product_cannot_be_deleted(): void
    {
        $product = $this->perfumeProduct();
        $variant = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { deleteProductVariant(id: $id) { id } }
            ', ['id' => (string) $variant->id])
            ->assertGraphQLErrorMessage(
                'A product needs at least one variant, so the last one cannot be deleted.'
            );

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
    }

    public function test_a_variant_can_be_deleted_while_another_one_remains(): void
    {
        $product = $this->perfumeProduct();

        $spare = $this->addVariant($product, [
            'volume_id' => $this->volumeModel('6 ml')->id,
            'price' => 1500,
        ]);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!) { deleteProductVariant(id: $id) { id } }
            ', ['id' => (string) $spare->id])
            ->assertJsonMissingPath('errors');

        $this->assertDatabaseMissing('product_variants', ['id' => $spare->id]);
    }

    public function test_the_service_can_still_replace_the_whole_tree(): void
    {
        // Regression guard: the "last variant" rule must not block an update that
        // removes every stored row while inserting its replacement.
        $product = $this->clothingProduct();
        $old = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!, $variants: [VariantInput!]) {
                    UpdateProduct(id: $id, variants: $variants) {
                        id
                        variants { id label is_default }
                    }
                }
            ', [
                'id' => (string) $product->id,
                'variants' => [[
                    'color_id' => $this->color('Ivory')->id,
                    'size_id' => $this->sizeModel('L')->id,
                    'price' => 6500,
                    'is_default' => true,
                    'images' => [['url' => 'products/new.jpg']],
                ]],
            ])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.UpdateProduct.variants.0.label', 'Ivory / L');

        $this->assertDatabaseMissing('product_variants', ['id' => $old->id]);
        $this->assertSame(1, ProductVariant::where('product_id', $product->id)->count());
    }

    public function test_a_discount_above_the_price_is_refused_by_the_service(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($variants: [VariantInput!]!) {
                    CreateProduct(type: "perfume", name: "Bad Discount", variants: $variants) { id }
                }
            ', [
                'variants' => [[
                    'volume_id' => $this->volumeModel('30 ml')->id,
                    'price' => 1000,
                    'discount_price' => 1500,
                    'images' => [['url' => 'a.jpg']],
                ]],
            ])
            ->assertGraphQLErrorMessage('The discount price of 30 ml must be lower than its price.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_the_database_also_refuses_a_discount_above_the_price(): void
    {
        $product = $this->perfumeProduct();
        $driver = Product::query()->getModel()->getConnection()->getDriverName();

        // SQLite cannot add a check to an existing table, so the rule lives in the
        // service and the model there; MySQL and Postgres carry it in the schema.
        if ($driver === 'sqlite') {
            $this->assertTrue(true);

            return;
        }

        $this->expectException(QueryException::class);

        ProductVariant::query()->insert([
            'product_id' => $product->id,
            'volume_id' => $this->volumeModel('6 ml')->id,
            'price' => 1000,
            'discount_price' => 1500,
            'stock' => 1,
            'is_default' => false,
            'is_active' => true,
        ]);
    }
}
