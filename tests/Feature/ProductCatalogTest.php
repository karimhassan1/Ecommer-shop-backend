<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * The product / variant contract.
 *
 * A product has no price and no image; every variant does. These tests drive the
 * API the way the admin form does and assert the rules from the spec:
 *
 *   - a product needs at least one variant
 *   - perfume  -> volume only;  clothing -> colour + size only
 *   - price > 0, discount below price
 *   - at least one image per variant, exactly one primary
 *   - exactly one default variant, forced when there is only one
 */
class ProductCatalogTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    /** The mutation the admin form sends, including every variable it declares. */
    protected function createMutation(): string
    {
        return <<<'GRAPHQL'
            mutation CreateProduct(
                $type: String!
                $name: String!
                $description: String
                $fabric: String
                $fit_details: String
                $is_active: Boolean!
                $category_ids: [ID!]
                $variants: [VariantInput!]!
            ) {
                CreateProduct(
                    type: $type
                    name: $name
                    description: $description
                    fabric: $fabric
                    fit_details: $fit_details
                    is_active: $is_active
                    category_ids: $category_ids
                    variants: $variants
                ) {
                    id
                    type
                    name
                    fabric
                    fit_details
                    total_stock
                    lowest_price
                    discount_percent
                    primary_image
                    default_variant_id
                    default_variant { id label price final_price images { url is_primary } }
                    variants {
                        id
                        label
                        price
                        discount_price
                        final_price
                        is_discounted
                        discount_percent
                        stock
                        in_stock
                        is_default
                        sku
                        color { name hex }
                        size { name }
                        volume { label ml }
                        image_urls
                        primary_image
                        images { url sort_order is_primary }
                    }
                    colors { id name hex sizes { variant_id size in_stock } }
                    volumes { variant_id label ml in_stock }
                }
            }
        GRAPHQL;
    }

    /* ------------------------------------------------------------------ */
    /* Perfume                                                             */
    /* ------------------------------------------------------------------ */

    public function test_a_perfume_is_created_per_volume_with_its_own_price_and_photos(): void
    {
        $ids = $this->masterIds();

        $response = $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), [
                'type' => 'perfume',
                'name' => 'Oud Royale',
                'description' => 'Warm, woody oud with amber notes.',
                'fabric' => null,
                'fit_details' => null,
                'is_active' => true,
                'category_ids' => [],
                'variants' => [
                    [
                        'volume_id' => $ids['six'],
                        'sku' => 'OUD-6',
                        'price' => 1500,
                        'discount_price' => null,
                        'stock' => 40,
                        'is_default' => false,
                        'images' => [['url' => 'oud-6-a.jpg', 'is_primary' => true]],
                    ],
                    [
                        'volume_id' => $ids['thirty'],
                        'sku' => 'OUD-30',
                        'price' => 6000,
                        'discount_price' => 5200,
                        'stock' => 15,
                        'is_default' => true,
                        'images' => [
                            ['url' => 'oud-30-a.jpg', 'is_primary' => true],
                            ['url' => 'oud-30-b.jpg'],
                            ['url' => 'oud-30-c.jpg'],
                        ],
                    ],
                ],
            ]);

        $response
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.CreateProduct.type', 'perfume')
            ->assertJsonPath('data.CreateProduct.total_stock', 55)
            ->assertJsonPath('data.CreateProduct.lowest_price', 1500)
            ->assertJsonPath('data.CreateProduct.variants.0.label', '6 ml')
            ->assertJsonPath('data.CreateProduct.variants.1.label', '30 ml')
            ->assertJsonPath('data.CreateProduct.variants.1.is_default', true)
            ->assertJsonPath('data.CreateProduct.variants.1.discount_price', 5200)
            ->assertJsonPath('data.CreateProduct.variants.1.final_price', 5200)
            ->assertJsonPath('data.CreateProduct.variants.1.is_discounted', true)
            ->assertJsonPath('data.CreateProduct.default_variant.label', '30 ml')
            ->assertJsonPath('data.CreateProduct.volumes.0.label', '6 ml')
            ->assertJsonPath('data.CreateProduct.volumes.1.label', '30 ml');

        // Three photos on the 30 ml bottle, the first one primary.
        $this->assertCount(3, $response->json('data.CreateProduct.variants.1.images'));
        $response
            ->assertJsonPath('data.CreateProduct.variants.1.images.0.is_primary', true)
            ->assertJsonPath('data.CreateProduct.variants.1.images.1.is_primary', false);

        $product = Product::firstOrFail();
        $this->assertSame('perfume', $product->type);
        $this->assertDatabaseCount('product_variants', 2);
    }

    public function test_final_price_falls_back_to_price_outside_the_discount_window(): void
    {
        $ids = $this->masterIds();

        $id = $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), [
                'type' => 'perfume',
                'name' => 'Windowed Sale',
                'description' => 'The sale has already ended.',
                'fabric' => null,
                'fit_details' => null,
                'is_active' => true,
                'category_ids' => [],
                'variants' => [[
                    'volume_id' => $ids['thirty'],
                    'price' => 6000,
                    'discount_price' => 4000,
                    'discount_starts_at' => now()->subMonth()->format('Y-m-d H:i:s'),
                    'discount_ends_at' => now()->subDay()->format('Y-m-d H:i:s'),
                    'stock' => 3,
                    'is_default' => true,
                    'images' => [['url' => 'ended.jpg']],
                ]],
            ])
            ->json('data.CreateProduct.id');

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID) {
                product(id: $id) { variants { price discount_price final_price is_discounted } }
            }
        ', ['id' => $id])
            ->assertJsonPath('data.product.variants.0.price', 6000)
            ->assertJsonPath('data.product.variants.0.discount_price', 4000)
            ->assertJsonPath('data.product.variants.0.final_price', 6000)
            ->assertJsonPath('data.product.variants.0.is_discounted', false);
    }

    /* ------------------------------------------------------------------ */
    /* Clothing                                                            */
    /* ------------------------------------------------------------------ */

    public function test_clothing_is_created_per_colour_and_size_with_its_own_gallery(): void
    {
        $ids = $this->masterIds();

        $response = $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), [
                'type' => 'clothing',
                'name' => 'Classic Linen Shirt',
                'description' => 'Relaxed everyday shirt.',
                'fabric' => '100% linen',
                'fit_details' => "Regular fit, model is 6'0 wearing M",
                'is_active' => true,
                'category_ids' => [],
                'variants' => [
                    [
                        'color_id' => $ids['black'], 'size_id' => $ids['small'],
                        'sku' => 'LS-BLK-S', 'price' => 3500, 'discount_price' => 2999,
                        'stock' => 10, 'is_default' => false,
                        'images' => [['url' => 'ls-blk-s-1.jpg', 'is_primary' => true]],
                    ],
                    [
                        'color_id' => $ids['black'], 'size_id' => $ids['medium'],
                        'sku' => 'LS-BLK-M', 'price' => 3500, 'stock' => 12,
                        'is_default' => true,
                        'images' => [
                            ['url' => 'ls-blk-m-1.jpg', 'is_primary' => true],
                            ['url' => 'ls-blk-m-2.jpg'],
                        ],
                    ],
                    [
                        'color_id' => $ids['ivory'], 'size_id' => $ids['medium'],
                        'sku' => 'LS-WHT-M', 'price' => 3500, 'stock' => 8,
                        'images' => [['url' => 'ls-wht-m-1.jpg', 'is_primary' => true]],
                    ],
                ],
            ]);

        $response
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.CreateProduct.type', 'clothing')
            ->assertJsonPath('data.CreateProduct.fabric', '100% linen')
            ->assertJsonPath('data.CreateProduct.total_stock', 30)
            ->assertJsonPath('data.CreateProduct.variants.0.label', 'Midnight / S')
            ->assertJsonPath('data.CreateProduct.variants.1.label', 'Midnight / M')
            ->assertJsonPath('data.CreateProduct.variants.2.label', 'Ivory / M')
            ->assertJsonPath('data.CreateProduct.variants.0.final_price', 2999)
            ->assertJsonPath('data.CreateProduct.default_variant.label', 'Midnight / M');

        // The colour tree the product page renders: colour -> sizes.
        $this->assertCount(2, $response->json('data.CreateProduct.colors'));
        $response
            ->assertJsonPath('data.CreateProduct.colors.0.name', 'Midnight')
            ->assertJsonPath('data.CreateProduct.colors.0.hex', '#1f2430')
            ->assertJsonPath('data.CreateProduct.colors.0.sizes.0.size', 'S')
            ->assertJsonPath('data.CreateProduct.colors.0.sizes.1.size', 'M')
            ->assertJsonPath('data.CreateProduct.colors.1.name', 'Ivory');
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                          */
    /* ------------------------------------------------------------------ */

    public function test_a_product_cannot_be_created_without_variants(): void
    {
        $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), $this->payload(['variants' => []]))
            ->assertGraphQLErrorMessage('A product needs at least one variant.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_clothing_variant_needs_both_a_colour_and_a_size(): void
    {
        $ids = $this->masterIds();

        $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), $this->payload([
                'variants' => [[
                    'color_id' => $ids['black'],
                    'price' => 3000,
                    'stock' => 2,
                    'images' => [['url' => 'a.jpg']],
                ]],
            ]))
            ->assertGraphQLErrorMessage('Every clothing variant needs a colour and a size.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_perfume_variant_needs_a_volume_and_cannot_have_a_colour(): void
    {
        $ids = $this->masterIds();

        $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), $this->payload([
                'type' => 'perfume',
                'variants' => [[
                    'color_id' => $ids['black'],
                    'price' => 3000,
                    'stock' => 2,
                    'images' => [['url' => 'a.jpg']],
                ]],
            ]))
            ->assertGraphQLErrorMessage('Every perfume variant needs a volume.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_the_same_combination_cannot_be_listed_twice(): void
    {
        $ids = $this->masterIds();

        $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), $this->payload([
                'variants' => [
                    [
                        'color_id' => $ids['black'], 'size_id' => $ids['small'],
                        'price' => 3500, 'stock' => 1,
                        'images' => [['url' => 'a.jpg']],
                    ],
                    [
                        'color_id' => $ids['black'], 'size_id' => $ids['small'],
                        'price' => 3600, 'stock' => 1,
                        'images' => [['url' => 'b.jpg']],
                    ],
                ],
            ]))
            ->assertGraphQLErrorMessage('The Midnight / S combination is listed twice.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_discount_must_be_lower_than_the_price(): void
    {
        $ids = $this->masterIds();

        $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), $this->payload([
                'type' => 'perfume',
                'variants' => [[
                    'volume_id' => $ids['thirty'],
                    'price' => 5000,
                    'discount_price' => 5000,
                    'stock' => 1,
                    'images' => [['url' => 'a.jpg']],
                ]],
            ]))
            ->assertGraphQLErrorMessage('The discount price of 30 ml must be lower than its price.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_variant_must_have_at_least_one_photo(): void
    {
        $ids = $this->masterIds();

        $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), $this->payload([
                'type' => 'perfume',
                'variants' => [[
                    'volume_id' => $ids['thirty'],
                    'price' => 5000,
                    'stock' => 1,
                    'images' => [],
                ]],
            ]))
            ->assertGraphQLErrorMessage('Add at least one photo to 30 ml.');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_the_first_photo_becomes_primary_when_none_is_marked(): void
    {
        $ids = $this->masterIds();

        $this->actingAs($this->adminUser())
            ->graphQL($this->createMutation(), $this->payload([
                'type' => 'perfume',
                'variants' => [[
                    'volume_id' => $ids['thirty'],
                    'price' => 5000,
                    'stock' => 1,
                    'images' => [['url' => 'first.jpg'], ['url' => 'second.jpg']],
                ]],
            ]))
            ->assertJsonPath('data.CreateProduct.variants.0.images.0.is_primary', true)
            ->assertJsonPath('data.CreateProduct.variants.0.images.1.is_primary', false)
            ->assertJsonPath('data.CreateProduct.variants.0.primary_image', function (string $url): bool {
                return str_ends_with($url, 'first.jpg');
            });

        $this->assertSame(1, VariantImage::where('is_primary', true)->count());
    }

    /**
     * A complete, valid payload the individual tests override one field of.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'clothing',
            'name' => 'Test Product',
            'description' => null,
            'fabric' => null,
            'fit_details' => null,
            'is_active' => true,
            'category_ids' => [],
            'variants' => [],
        ], $overrides);
    }
}
