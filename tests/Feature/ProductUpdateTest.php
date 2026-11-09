<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Editing an existing product without losing history.
 *
 * The admin form always sends the full variant tree. The API matches rows by
 * id, inserts the ones without an id and removes the ones that disappeared —
 * deactivating (not deleting) a variant that an order already points at.
 */
class ProductUpdateTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function updateMutation(): string
    {
        return <<<'GRAPHQL'
            mutation UpdateProduct(
                $id: ID!
                $name: String
                $description: String
                $fabric: String
                $fit_details: String
                $is_active: Boolean
                $variants: [VariantInput!]
            ) {
                UpdateProduct(
                    id: $id
                    name: $name
                    description: $description
                    fabric: $fabric
                    fit_details: $fit_details
                    is_active: $is_active
                    variants: $variants
                ) {
                    id
                    name
                    is_active
                    total_stock
                    default_variant_id
                    variants { id label price discount_price stock is_active is_default }
                }
            }
        GRAPHQL;
    }

    /** A payload row for an existing variant, with its photos. */
    protected function row(ProductVariant $variant, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) $variant->id,
            'color_id' => $variant->color_id,
            'size_id' => $variant->size_id,
            'volume_id' => $variant->volume_id,
            'price' => $variant->price,
            'stock' => $variant->stock,
            'is_default' => $variant->is_default,
            'images' => [['url' => 'products/kept.jpg', 'is_primary' => true]],
        ], $overrides);
    }

    public function test_a_variant_with_an_id_is_updated_in_place(): void
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [$this->row($variant, [
                    'price' => 7200,
                    'discount_price' => 6900,
                    'stock' => 3,
                ])],
            ])
            ->assertJsonPath('data.UpdateProduct.variants.0.id', (string) $variant->id)
            ->assertJsonPath('data.UpdateProduct.variants.0.price', 7200)
            ->assertJsonPath('data.UpdateProduct.variants.0.discount_price', 6900)
            ->assertJsonPath('data.UpdateProduct.total_stock', 3);

        // Same row, so cart lines pointing at it still resolve.
        $this->assertSame(1, ProductVariant::where('product_id', $product->id)->count());
        $this->assertSame(7200.0, $variant->fresh()->price);
    }

    public function test_a_variant_without_an_id_is_inserted(): void
    {
        $product = $this->clothingProduct();
        $first = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [
                    $this->row($first),
                    [
                        'color_id' => $first->color_id,
                        'size_id' => $this->sizeModel('L')->id,
                        'price' => 6900,
                        'stock' => 4,
                        'images' => [['url' => 'products/large.jpg']],
                    ],
                ],
            ])
            ->assertJsonPath('data.UpdateProduct.total_stock', 14);

        $this->assertSame(2, ProductVariant::where('product_id', $product->id)->count());
    }

    public function test_a_variant_missing_from_the_payload_is_deleted(): void
    {
        $product = $this->clothingProduct();
        $keep = $this->defaultVariant($product);
        $remove = $this->addVariant($product, [
            'color_id' => $this->color('Ivory')->id,
            'size_id' => $this->sizeModel('L')->id,
            'price' => 6500,
        ]);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [$this->row($keep)],
            ])
            ->assertJsonPath('data.UpdateProduct.total_stock', 10);

        $this->assertDatabaseMissing('product_variants', ['id' => $remove->id]);
    }

    public function test_a_variant_that_has_orders_is_deactivated_instead_of_deleted(): void
    {
        $product = $this->clothingProduct();
        $keep = $this->defaultVariant($product);

        $ordered = $this->addVariant($product, [
            'color_id' => $this->color('Ivory')->id,
            'size_id' => $this->sizeModel('L')->id,
            'price' => 6500,
            'stock' => 2,
        ]);

        // Pretend this variant was sold once.
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Buyer',
            'phone' => '03001234567',
            'email' => 'buyer@example.com',
            'province' => 'Punjab',
            'city' => 'Lahore',
            'street' => '1 Mall Road',
            'postal_code' => '54000',
            'status' => Order::STATUS_PENDING,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $ordered->id,
            'quantity' => 1,
            'price' => 6500,
        ]);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [$this->row($keep)],
            ])
            ->assertJsonMissingPath('errors');

        // The row survives (the order line keeps its link) but is not sold any more.
        $ordered->refresh();
        $this->assertFalse($ordered->is_active);
        $this->assertSame($product->id, $ordered->product_id);
    }

    public function test_removing_the_default_variant_requires_a_new_default(): void
    {
        $product = $this->clothingProduct();
        $default = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [[
                    'color_id' => $this->color('Ivory')->id,
                    'size_id' => $this->sizeModel('L')->id,
                    'price' => 6500,
                    'stock' => 4,
                    // No is_default anywhere in the payload.
                    'images' => [['url' => 'products/new.jpg']],
                ]],
            ])
            ->assertGraphQLErrorMessage(
                'You removed the default variant. Choose another variant as the default in the same save.'
            );

        $this->assertDatabaseHas('product_variants', ['id' => $default->id, 'is_default' => true]);
    }

    public function test_removing_the_default_variant_is_allowed_when_a_new_one_is_named(): void
    {
        $product = $this->clothingProduct();
        $default = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [[
                    'color_id' => $this->color('Ivory')->id,
                    'size_id' => $this->sizeModel('L')->id,
                    'price' => 6500,
                    'stock' => 4,
                    'is_default' => true,
                    'images' => [['url' => 'products/new.jpg']],
                ]],
            ])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.UpdateProduct.variants.0.label', 'Ivory / L')
            ->assertJsonPath('data.UpdateProduct.variants.0.is_default', true);

        $this->assertDatabaseMissing('product_variants', ['id' => $default->id]);
    }

    public function test_a_row_sent_without_an_id_reuses_the_existing_combination(): void
    {
        $product = $this->perfumeProduct();
        $six = $this->addVariant($product, [
            'volume_id' => $this->volumeModel('6 ml')->id,
            'price' => 1500,
            'stock' => 4,
        ]);

        $thirty = $this->defaultVariant($product);

        // No ids at all: both rows must be matched on their volume, not inserted.
        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [
                    [
                        'volume_id' => $this->volumeModel('6 ml')->id,
                        'price' => 1600,
                        'stock' => 5,
                        'images' => [['url' => 'products/six.jpg']],
                    ],
                    [
                        'volume_id' => $this->volumeModel('30 ml')->id,
                        'price' => 8600,
                        'stock' => 7,
                        'is_default' => true,
                        'images' => [['url' => 'products/thirty.jpg']],
                    ],
                ],
            ])
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.UpdateProduct.total_stock', 12);

        // Rows are returned in id order, and every id is the original one: the
        // payload created no new variants.
        $ids = collect(
            $this->actingAs($this->adminUser())
                ->graphQL($this->updateMutation(), ['id' => (string) $product->id])
                ->json('data.UpdateProduct.variants')
        )->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $this->assertSame(
            collect([$six->id, $thirty->id])->sort()->values()->all(),
            $ids
        );
        $this->assertSame(2, ProductVariant::where('product_id', $product->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* Default stays buyable                                               */
    /* ------------------------------------------------------------------ */

    public function test_the_default_variant_cannot_be_deactivated(): void
    {
        $product = $this->clothingProduct();
        $first = $this->defaultVariant($product);

        $second = $this->addVariant($product, [
            'color_id' => $this->color('Ivory')->id,
            'size_id' => $this->sizeModel('L')->id,
            'price' => 7000,
        ]);

        // The payload asks for a default that is switched off: the product page
        // would otherwise open on a combination nobody can buy.
        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [
                    $this->row($first, ['is_default' => false]),
                    $this->row($second, ['is_default' => true, 'is_active' => false]),
                ],
            ])
            ->assertJsonMissingPath('errors');

        $default = $this->defaultVariant($product->fresh());

        $this->assertTrue($default->is_active);
        $this->assertSame($first->id, $default->id);
    }

    public function test_an_inactive_variant_is_saved_as_inactive(): void
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [$this->row($variant, ['is_active' => false])],
            ])
            ->assertJsonPath('data.UpdateProduct.variants.0.is_active', false);

        $this->assertFalse($variant->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ */
    /* Photos                                                              */
    /* ------------------------------------------------------------------ */

    public function test_a_resubmitted_photo_keeps_its_row(): void
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);
        $photo = VariantImage::where('product_variant_id', $variant->id)->firstOrFail();

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [[
                    'id' => (string) $variant->id,
                    'color_id' => $variant->color_id,
                    'size_id' => $variant->size_id,
                    'price' => $variant->price,
                    'is_default' => true,
                    'images' => [
                        // Sent back with its id, exactly as the admin form does.
                        ['id' => (string) $photo->id, 'url' => $photo->getRawOriginal('url')],
                    ],
                ]],
            ])
            ->assertJsonMissingPath('errors');

        $this->assertSame(
            [$photo->id],
            VariantImage::where('product_variant_id', $variant->id)->pluck('id')->all(),
            'a photo that is still there must not be recreated'
        );
    }

    public function test_a_photo_dropped_from_the_payload_is_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/keep.jpg', 'bytes');
        Storage::disk('public')->put('products/drop.jpg', 'bytes');

        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $variant->images()->delete();

        $keep = VariantImage::create([
            'product_variant_id' => $variant->id,
            'url' => 'products/keep.jpg',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        VariantImage::create([
            'product_variant_id' => $variant->id,
            'url' => 'products/drop.jpg',
            'sort_order' => 1,
            'is_primary' => false,
        ]);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [[
                    'id' => (string) $variant->id,
                    'color_id' => $variant->color_id,
                    'size_id' => $variant->size_id,
                    'price' => $variant->price,
                    'is_default' => true,
                    'images' => [['id' => (string) $keep->id, 'url' => 'products/keep.jpg']],
                ]],
            ])
            ->assertJsonMissingPath('errors');

        $this->assertDatabaseMissing('variant_images', ['url' => 'products/drop.jpg']);
        $this->assertDatabaseHas('variant_images', ['id' => $keep->id]);

        // Dropping a photo also reclaims its file.
        Storage::disk('public')->assertMissing('products/drop.jpg');
        Storage::disk('public')->assertExists('products/keep.jpg');
    }

    public function test_a_new_photo_without_an_id_is_inserted(): void
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);
        $photo = VariantImage::where('product_variant_id', $variant->id)->firstOrFail();

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'variants' => [[
                    'id' => (string) $variant->id,
                    'color_id' => $variant->color_id,
                    'size_id' => $variant->size_id,
                    'price' => $variant->price,
                    'is_default' => true,
                    'images' => [
                        ['id' => (string) $photo->id, 'url' => $photo->getRawOriginal('url')],
                        ['url' => 'products/fresh-upload.jpg'],
                    ],
                ]],
            ])
            ->assertJsonMissingPath('errors');

        $urls = VariantImage::where('product_variant_id', $variant->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (VariantImage $image): string => $image->getRawOriginal('url'))
            ->all();

        $this->assertCount(2, $urls);
        $this->assertSame('products/test.jpg', $urls[0]);
        $this->assertSame('products/fresh-upload.jpg', $urls[1]);
    }

    /* ------------------------------------------------------------------ */
    /* Product level fields                                                */
    /* ------------------------------------------------------------------ */

    public function test_the_product_fields_can_be_patched_without_touching_the_variants(): void
    {
        $product = $this->clothingProduct();
        $variant = $this->defaultVariant($product);

        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), [
                'id' => (string) $product->id,
                'name' => 'Renamed Dress',
                'is_active' => false,
            ])
            ->assertJsonPath('data.UpdateProduct.name', 'Renamed Dress')
            ->assertJsonPath('data.UpdateProduct.is_active', false)
            ->assertJsonPath('data.UpdateProduct.total_stock', 10);

        $this->assertSame($variant->id, $this->defaultVariant($product->fresh())->id);
    }

    public function test_an_unknown_product_is_rejected(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL($this->updateMutation(), ['id' => '999999', 'name' => 'Ghost'])
            ->assertGraphQLErrorMessage('That product could not be found.');
    }

    public function test_the_product_row_stores_no_price_image_or_stock(): void
    {
        $this->perfumeProduct();

        $columns = Product::query()->getModel()->getConnection()
            ->getSchemaBuilder()->getColumnListing('products');

        $this->assertContains('type', $columns);
        $this->assertContains('fabric', $columns);
        $this->assertContains('fit_details', $columns);

        foreach (['price', 'photo', 'inventory', 'compare_at_price', 'product_type'] as $gone) {
            $this->assertNotContains($gone, $columns);
        }
    }
}
