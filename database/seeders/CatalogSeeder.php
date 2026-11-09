<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Size;
use App\Models\VariantImage;
use App\Models\Volume;
use App\Services\SettingService;
use Illuminate\Database\Seeder;

/**
 * A realistic Pakistani catalogue.
 *
 * Products carry only descriptive data (type, name, description, fabric, fit);
 * every price, discount, stock count and photo lives on a variant, exactly as
 * the admin form writes them:
 *
 *   clothing -> one variant per colour x size
 *   perfume  -> one variant per volume
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $brands = collect(['Rosera Bloom', 'D&J Collective', 'Atelier Noir'])
            ->mapWithKeys(fn (string $name): array => [
                $name => Brand::firstOrCreate(['name' => $name]),
            ]);

        $categories = [
            ['name' => 'Unstitched Lawn', 'type' => 'clothing', 'sort' => 1],
            ['name' => 'Pret & Ready to Wear', 'type' => 'clothing', 'sort' => 2],
            ['name' => 'Formal Wear', 'type' => 'clothing', 'sort' => 3],
            ['name' => 'Kurta & Shalwar', 'type' => 'clothing', 'sort' => 4],
            ['name' => 'Eau de Parfum', 'type' => 'perfume', 'sort' => 5],
            ['name' => 'Attar & Oud', 'type' => 'perfume', 'sort' => 6],
        ];

        $categoryMap = [];

        foreach ($categories as $row) {
            $categoryMap[$row['name']] = Category::updateOrCreate(
                ['name' => $row['name']],
                ['type' => $row['type'], 'sort_order' => $row['sort']]
            );
        }

        foreach ($this->clothingProducts() as $data) {
            $this->makeProduct($data, $brands, $categoryMap, Product::TYPE_CLOTHING);
        }

        foreach ($this->perfumeProducts() as $data) {
            $this->makeProduct($data, $brands, $categoryMap, Product::TYPE_PERFUME);
        }

        // Keep the shipping / payment settings aligned with PKR defaults.
        foreach (SettingService::DEFAULTS as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        app(SettingService::class)->flush();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  \Illuminate\Support\Collection<string, Brand>  $brands
     * @param  array<string, Category>  $categoryMap
     */
    protected function makeProduct(array $data, $brands, array $categoryMap, string $type): void
    {
        $isPerfume = $type === Product::TYPE_PERFUME;

        $product = Product::updateOrCreate(
            ['name' => $data['name']],
            [
                'type' => $type,
                'description' => $data['description'],
                // Perfume keeps its longevity in the "fit details" slot so the
                // product page always has a second detail block to show.
                'fabric' => $isPerfume ? null : ($data['fabric'] ?? null),
                'fit_details' => $isPerfume
                    ? ($data['longevity'] ?? null)
                    : ($data['fit'] ?? null),
                'is_active' => true,
                // The demo catalogue marks a few pieces as home page features.
                'is_featured' => (bool) ($data['featured'] ?? false),
            ]
        );

        $product->brands()->syncWithoutDetaching([$brands[$data['brand']]->id]);

        $product->categories()->syncWithoutDetaching(
            collect($data['categories'])
                ->map(fn (string $name): int => $categoryMap[$name]->id)
                ->all()
        );

        // Rebuild the variant matrix so re-seeding stays idempotent and the
        // demo data matches what the admin panel produces.
        $product->variants()->delete();

        $defaultIndex = $data['default'] ?? 0;

        foreach (array_values($data['variants']) as $index => $row) {
            $this->makeVariant($product, $isPerfume, $row, $index, $defaultIndex);
        }
    }

    /**
     * One buyable combination, with its own price, stock and photos.
     *
     * @param  array<string, mixed>  $row
     */
    protected function makeVariant(
        Product $product,
        bool $isPerfume,
        array $row,
        int $index,
        int $defaultIndex,
    ): void {
        $color = $isPerfume ? null : Color::where('name', $row['color'])->first();
        $size = $isPerfume ? null : Size::where('name', $row['size'])->first();
        $volume = $isPerfume ? Volume::where('label', $row['size'])->first() : null;

        if ($isPerfume && ! $volume) {
            return;
        }

        if (! $isPerfume && (! $color || ! $size)) {
            return;
        }

        /** @var ProductVariant $variant */
        $variant = $product->variants()->create([
            'color_id' => $color?->id,
            'size_id' => $size?->id,
            'volume_id' => $volume?->id,
            'sku' => $row['sku'] ?? null,
            'price' => $row['price'],
            'discount_price' => $row['discount'] ?? null,
            'stock' => $row['stock'],
            'is_default' => $index === $defaultIndex,
            'is_active' => true,
        ]);

        // Every variant needs at least one photo, so the demo data mirrors it.
        $label = $isPerfume
            ? str_replace(' ', '-', strtolower((string) ($volume?->label ?? 'perfume')))
            : str_replace(' ', '-', strtolower($color->name.'-'.$size->name));

        foreach ($row['images'] ?? [1, 2] as $sort => $suffix) {
            VariantImage::create([
                'product_variant_id' => $variant->id,
                'url' => "products/{$product->id}-{$label}-{$suffix}.jpg",
                'sort_order' => $sort,
                'is_primary' => $sort === 0,
            ]);
        }
    }

    /**
     * Prepared clothing: each item ships in several sizes and colours.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function clothingProducts(): array
    {
        return [
            [
                'name' => 'Embroidered Lawn Kurta',
                'description' => 'Three piece embroidered lawn suit with a printed dupatta. Ready to wear, lightly stitched at the sides.',
                'featured' => true,
                'fabric' => 'Lawn cotton',
                'fit' => 'Relaxed straight cut · model is 5\'7 wearing M',
                'brand' => 'Rosera Bloom',
                'categories' => ['Pret & Ready to Wear', 'Unstitched Lawn'],
                'default' => 1,
                'variants' => $this->sizeColorMatrix(
                    ['S', 'M', 'L', 'XL'],
                    [['Rose Pink', '#e8a0bf'], ['Ivory', '#f5f0e1'], ['Midnight', '#1f2430']],
                    6490,
                    8990,
                ),
            ],
            [
                'name' => 'Cotton Kurta Shalwar Set',
                'description' => 'Everyday cotton kurta with a matching shalwar. Breathable weave suited to Pakistani summers.',
                'fabric' => '100% cotton',
                'fit' => 'Regular fit',
                'brand' => 'D&J Collective',
                'categories' => ['Kurta & Shalwar', 'Pret & Ready to Wear'],
                'variants' => $this->sizeColorMatrix(
                    ['S', 'M', 'L', 'XL', 'XXL'],
                    [['White', '#ffffff'], ['Sky Blue', '#a9cce3'], ['Olive', '#708238']],
                    3990,
                ),
            ],
            [
                'name' => 'Chiffon Formal Maxi',
                'description' => 'Hand embellished chiffon maxi with inner lining. Made for weddings and evening events.',
                'fabric' => 'Chiffon with cotton lining',
                'fit' => 'Floor length, fitted bodice',
                'brand' => 'Atelier Noir',
                'categories' => ['Formal Wear'],
                'variants' => $this->sizeColorMatrix(
                    ['S', 'M', 'L'],
                    [['Maroon', '#7b1f2b'], ['Emerald', '#0f7a46']],
                    18500,
                    22500,
                ),
            ],
            [
                'name' => 'Linen Co-ord Set',
                'description' => 'Breezy linen shirt and trouser co-ord. Pre-shrunk and garment washed for a soft hand feel.',
                'fabric' => 'Washed linen',
                'fit' => 'Oversized',
                'brand' => 'Rosera Bloom',
                'categories' => ['Pret & Ready to Wear'],
                'variants' => $this->sizeColorMatrix(
                    ['S', 'M', 'L', 'XL'],
                    [['Sand', '#d9c9a3'], ['Charcoal', '#36454f']],
                    7250,
                ),
            ],
        ];
    }

    /**
     * Perfume: sold per volume, with no colour.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function perfumeProducts(): array
    {
        return [
            [
                'name' => 'Rose Oud Eau de Parfum',
                'description' => 'A warm rose and oud composition built for evening wear. Top: Taif rose · Heart: oud, saffron · Base: amber, musk.',
                'longevity' => '8–10 hours',
                'featured' => true,
                'brand' => 'Rosera Bloom',
                'categories' => ['Eau de Parfum'],
                'default' => 1,
                'variants' => $this->volumeMatrix([
                    ['30 ml', 8500, 14, true],
                    ['50 ml', 12900, 9],
                    ['100 ml', 19900, 4],
                ]),
            ],
            [
                'name' => 'Musk Al Haramain Attar',
                'description' => 'Alcohol free attar with a clean white musk profile. White musk, sandalwood and soft vanilla.',
                'longevity' => '6–8 hours',
                'brand' => 'Atelier Noir',
                'categories' => ['Attar & Oud'],
                'variants' => $this->volumeMatrix([
                    ['6 ml', 2490, 40],
                    ['12 ml', 4290, 22],
                ]),
            ],
            [
                'name' => 'Citrus Bloom Eau de Toilette',
                'description' => 'Bright bergamot and neroli, light enough for the office and hot afternoons. Bergamot, neroli, white tea, cedar.',
                'longevity' => '4–6 hours',
                'brand' => 'D&J Collective',
                'categories' => ['Eau de Parfum'],
                'variants' => $this->volumeMatrix([
                    ['50 ml', 5900, 18],
                    ['100 ml', 9400, 7],
                ]),
            ],
        ];
    }

    /**
     * Build one variant per colour × size combination, all at the same price.
     *
     * @param  array<int, string>  $sizes
     * @param  array<int, array{0: string, 1: string}>  $colors
     * @return array<int, array<string, mixed>>
     */
    protected function sizeColorMatrix(array $sizes, array $colors, float $price, ?float $compareAt = null): array
    {
        $variants = [];

        foreach ($colors as [$name]) {
            foreach ($sizes as $size) {
                $variants[] = [
                    'size' => $size,
                    'color' => $name,
                    'price' => $price,
                    // A sale price a few percent under the regular price keeps
                    // the storefront's strike-through visible in the demo data.
                    'discount' => $compareAt !== null ? round($price * 0.9, 2) : null,
                    'stock' => random_int(3, 12),
                ];
            }
        }

        return $variants;
    }

    /**
     * Turn `[label, price, stock, onSale?]` rows into variant rows.
     *
     * The fourth value is only a flag meaning "put this one on sale". The row's
     * `price` is the regular price a customer pays full price for, and
     * `discount_price` is the *lower* sale price (10% off), which is what
     * `ProductVariantService` and the database CHECK both require.
     */
    protected function volumeMatrix(array $volumes): array
    {
        return array_map(
            fn (array $volume): array => [
                'size' => $volume[0],
                'price' => $volume[1],
                'stock' => $volume[2],
                'discount' => isset($volume[3]) ? round($volume[1] * 0.9, 2) : null,
            ],
            $volumes
        );
    }
}
