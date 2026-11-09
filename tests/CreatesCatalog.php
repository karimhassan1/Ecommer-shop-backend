<?php

namespace Tests;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\VariantImage;
use App\Models\Volume;
use Database\Seeders\VariantMasterSeeder;
use Illuminate\Support\Collection;

/**
 * Builds catalog fixtures that obey the variant contract.
 *
 * A product has no price and no image; every price, stock count and photo lives
 * on a variant, and a variant needs at least one image. Tests therefore create
 * a product *through* a variant instead of setting `price` on the product.
 */
trait CreatesCatalog
{
    protected bool $mastersSeeded = false;

    /** Seed the colour / size / volume master lists once per test. */
    protected function seedVariantMasters(): void
    {
        if ($this->mastersSeeded) {
            return;
        }

        $this->seed(VariantMasterSeeder::class);

        $this->mastersSeeded = true;
    }

    protected function color(string $name): Color
    {
        $this->seedVariantMasters();

        return Color::where('name', $name)->firstOrFail();
    }

    protected function sizeModel(string $name): Size
    {
        $this->seedVariantMasters();

        return Size::where('name', $name)->firstOrFail();
    }

    protected function volumeModel(string $label): Volume
    {
        $this->seedVariantMasters();

        return Volume::where('label', $label)->firstOrFail();
    }

    /**
     * A clothing product with a single colour/size variant.
     */
    protected function clothingProduct(array $attributes = []): Product
    {
        $product = Product::create(array_merge([
            'type' => Product::TYPE_CLOTHING,
            'name' => 'Silk Bloom Midi Dress',
            'description' => 'A flowing midi dress.',
            'fabric' => '100% silk',
            'fit_details' => 'Regular fit',
            'is_active' => true,
        ], $attributes));

        $this->addVariant($product, [
            'color_id' => $this->color('Rose Pink')->id,
            'size_id' => $this->sizeModel('M')->id,
            'price' => 6500,
            'stock' => 10,
            'is_default' => true,
        ]);

        return $product->fresh();
    }

    /**
     * A perfume product with a single volume variant.
     */
    protected function perfumeProduct(array $attributes = []): Product
    {
        $product = Product::create(array_merge([
            'type' => Product::TYPE_PERFUME,
            'name' => 'Rose Oud Eau de Parfum',
            'description' => 'Warm rose and oud.',
            'is_active' => true,
        ], $attributes));

        $this->addVariant($product, [
            'volume_id' => $this->volumeModel('30 ml')->id,
            'price' => 8500,
            'stock' => 10,
            'is_default' => true,
        ]);

        return $product->fresh();
    }

    /**
     * Attach one variant (with a photo) to a product.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function addVariant(Product $product, array $attributes = []): ProductVariant
    {
        /** @var ProductVariant $variant */
        $variant = $product->variants()->create(array_merge([
            'price' => 5000,
            'stock' => 5,
            'is_active' => true,
            'is_default' => false,
        ], $attributes));

        $this->addImage($variant);

        return $variant->fresh();
    }

    /** Give a variant its required photo. */
    protected function addImage(ProductVariant $variant, string $url = 'products/test.jpg'): VariantImage
    {
        return VariantImage::create([
            'product_variant_id' => $variant->id,
            'url' => $url,
            'sort_order' => 0,
            'is_primary' => true,
        ]);
    }

    /**
     * The default (or first) variant of a product.
     */
    protected function defaultVariant(Product $product): ProductVariant
    {
        /** @var ProductVariant $variant */
        $variant = $product->variants()->where('is_default', true)->first()
            ?? $product->variants()->first();

        return $variant;
    }

    /**
     * Ids of the master lists used by the payload builders below.
     *
     * @return Collection<string, int>
     */
    protected function masterIds(): Collection
    {
        $this->seedVariantMasters();

        return collect([
            'pink' => $this->color('Rose Pink')->id,
            'ivory' => $this->color('Ivory')->id,
            'black' => $this->color('Midnight')->id,
            'small' => $this->sizeModel('S')->id,
            'medium' => $this->sizeModel('M')->id,
            'large' => $this->sizeModel('L')->id,
            'six' => $this->volumeModel('6 ml')->id,
            'thirty' => $this->volumeModel('30 ml')->id,
            'hundred' => $this->volumeModel('100 ml')->id,
        ]);
    }
}
