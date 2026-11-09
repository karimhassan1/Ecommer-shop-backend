<?php declare(strict_types=1);

namespace App\Services;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\VariantImage;
use App\Models\Volume;
use GraphQL\Error\UserError;
use Illuminate\Support\Collection;

/**
 * The single place where a product's variants are validated and written.
 *
 * Every rule from the catalogue contract lives here so the create mutation and
 * the update mutation cannot drift apart:
 *
 *   - a product needs at least one variant
 *   - perfume  -> volume_id, never colour/size, no duplicate volume
 *   - clothing -> colour_id AND size_id, never volume, no duplicate combo
 *   - every variant needs price > 0 with any discount below that price
 *   - every variant needs at least one image, exactly one of them primary
 *   - exactly one variant is the default; with a single variant it is forced
 *
 * Updates match rows by id: an id updates, no id inserts, and a stored variant
 * missing from the payload is deleted — or soft deleted when an order already
 * points at it, so order history keeps its link.
 */
class ProductVariantService
{
    /** Variant ids that exist on the product before this sync. */
    protected Collection $existing;

    /**
     * Validate and persist the variant tree of a product.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    public function sync(Product $product, array $variants): void
    {
        if ($variants === []) {
            throw new UserError('A product needs at least one variant.');
        }

        $this->existing = $product->variants()->get()->keyBy('id');

        $rows = $this->normalise($product, $variants);

        // Decide which stored row every payload row maps to *before* writing
        // anything, so a row sent without an id updates the existing row for the
        // same combination instead of colliding with the unique index, and so
        // stale rows are cleared out before the new ones are inserted.
        $rows = $this->attachExisting($rows);

        $this->removeMissing($product, $rows);

        foreach ($rows as $index => $row) {
            $variant = $this->persistVariant($product, $row);

            $this->syncImages($variant, $row['images']);

            $rows[$index]['variant'] = $variant;
        }

        $this->applyDefault($product, $rows);
    }

    /**
     * Point every payload row at the stored variant it represents.
     *
     * An explicit id wins. Otherwise a row is matched on its identifying
     * combination (volume, or colour + size) — which is exactly what the unique
     * indexes enforce — and adopted, so the admin form can send ids or not.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function attachExisting(array $rows): array
    {
        $claimed = [];

        // Explicit ids first, so they can never be stolen by a combination
        // match from another row.
        foreach ($rows as $row) {
            if ($row['id'] !== null) {
                $claimed[] = (int) $row['id'];
            }
        }

        foreach ($rows as $index => $row) {
            if ($row['id'] !== null) {
                if (! $this->existing->has($row['id'])) {
                    throw new UserError('One of the variants you are saving no longer exists.');
                }

                continue;
            }

            $match = $this->existing->first(
                fn (ProductVariant $variant): bool => ! in_array((int) $variant->id, $claimed, true)
                    && $this->sameCombination($variant, $row)
            );

            if ($match) {
                $rows[$index]['id'] = (int) $match->id;
                $claimed[] = (int) $match->id;
            }
        }

        return $rows;
    }

    /**
     * Does a stored variant represent the same combination as a payload row?
     *
     * @param  array<string, mixed>  $row
     */
    protected function sameCombination(ProductVariant $variant, array $row): bool
    {
        if ($row['volume_id'] !== null) {
            return (int) $variant->volume_id === (int) $row['volume_id'];
        }

        return (int) $variant->color_id === (int) $row['color_id']
            && (int) $variant->size_id === (int) $row['size_id'];
    }

    /**
     * Turn the raw payload into validated, database ready rows.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array<string, mixed>>
     */
    protected function normalise(Product $product, array $variants): array
    {
        $type = $product->type;
        $seen = [];
        $defaults = 0;
        $rows = [];

        foreach (array_values($variants) as $payload) {
            $color = $this->resolve(Color::class, $payload['color_id'] ?? null, 'colour');
            $size = $this->resolve(Size::class, $payload['size_id'] ?? null, 'size');
            $volume = $this->resolve(Volume::class, $payload['volume_id'] ?? null, 'volume');

            if ($type === Product::TYPE_PERFUME) {
                $this->assertPerfumeShape($volume, $color, $size);

                $key = 'v'.$volume->id;
                $label = $volume->label;
            } else {
                $this->assertClothingShape($color, $size, $volume);

                $key = 'c'.$color->id.'-s'.$size->id;
                $label = $color->name.' / '.$size->name;
            }

            if (isset($seen[$key])) {
                throw new UserError(
                    $type === Product::TYPE_PERFUME
                        ? "The {$label} volume is listed twice."
                        : "The {$label} combination is listed twice."
                );
            }

            $seen[$key] = true;

            $price = $this->price($payload['price'] ?? null, $label);
            $isDefault = (bool) ($payload['is_default'] ?? false);

            if ($isDefault) {
                $defaults++;
            }

            $rows[] = [
                'id' => isset($payload['id']) && $payload['id'] !== ''
                    ? (int) $payload['id']
                    : null,
                'color_id' => $type === Product::TYPE_CLOTHING ? $color->id : null,
                'size_id' => $type === Product::TYPE_CLOTHING ? $size->id : null,
                'volume_id' => $type === Product::TYPE_PERFUME ? $volume->id : null,
                'sku' => $this->sku($payload['sku'] ?? null, $payload['id'] ?? null),
                'price' => $price,
                'discount_price' => $this->discount($payload['discount_price'] ?? null, $price, $label),
                'discount_starts_at' => $payload['discount_starts_at'] ?? null,
                'discount_ends_at' => $payload['discount_ends_at'] ?? null,
                'stock' => $this->stock($payload['stock'] ?? 0, $label),
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'is_default' => $isDefault,
                'images' => $this->images($payload['images'] ?? [], $label),
            ];
        }

        if ($defaults > 1) {
            throw new UserError('Only one variant can be the default.');
        }

        return $rows;
    }

    /* --------------------------------------------------------------------- */
    /* Shape rules                                                           */
    /* --------------------------------------------------------------------- */

    protected function assertPerfumeShape(?Volume $volume, ?Color $color, ?Size $size): void
    {
        if (! $volume) {
            throw new UserError('Every perfume variant needs a volume.');
        }

        if ($color || $size) {
            throw new UserError('A perfume variant cannot have a colour or a size.');
        }
    }

    protected function assertClothingShape(?Color $color, ?Size $size, ?Volume $volume): void
    {
        if (! $color || ! $size) {
            throw new UserError('Every clothing variant needs a colour and a size.');
        }

        if ($volume) {
            throw new UserError('A clothing variant cannot have a volume.');
        }
    }

    /* --------------------------------------------------------------------- */
    /* Field rules                                                           */
    /* --------------------------------------------------------------------- */

    protected function price(mixed $value, string $label): float
    {
        $price = is_numeric($value) ? (float) $value : 0.0;

        if ($price <= 0) {
            throw new UserError("The price of {$label} must be greater than zero.");
        }

        return round($price, 2);
    }

    protected function discount(mixed $value, float $price, string $label): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new UserError("The discount price of {$label} must be a number.");
        }

        $discount = round((float) $value, 2);

        // Zero or negative simply means "no discount".
        if ($discount <= 0) {
            return null;
        }

        if ($discount >= $price) {
            throw new UserError("The discount price of {$label} must be lower than its price.");
        }

        return $discount;
    }

    protected function stock(mixed $value, string $label): int
    {
        $stock = is_numeric($value) ? (int) $value : 0;

        if ($stock < 0) {
            throw new UserError("The stock of {$label} cannot be negative.");
        }

        return $stock;
    }

    protected function sku(mixed $value, mixed $ignoreId): ?string
    {
        $sku = trim((string) ($value ?? ''));

        if ($sku === '') {
            return null;
        }

        $taken = ProductVariant::query()
            ->where('sku', $sku)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot((int) $ignoreId))
            ->exists();

        if ($taken) {
            throw new UserError("The SKU {$sku} is already used by another variant.");
        }

        return $sku;
    }

    /**
     * Validate a variant's gallery: at least one image, exactly one primary.
     *
     * When nothing is marked primary the first image becomes it, which is what
     * the admin form relies on after a single upload.
     *
     * A row that carries an `id` keeps that stored photo; a row without one is a
     * new upload. Photos the payload no longer contains are removed later, in
     * `syncImages`, so a deleted photo also deletes its file.
     *
     * @return array<int, array{id: int|null, url: string, is_primary: bool}>
     */
    protected function images(mixed $images, string $label): array
    {
        $rows = [];
        $primaries = 0;

        foreach (is_array($images) ? array_values($images) : [] as $image) {
            $url = trim((string) (is_array($image) ? ($image['url'] ?? '') : $image));

            if ($url === '') {
                continue;
            }

            $isPrimary = (bool) (is_array($image) ? ($image['is_primary'] ?? false) : false);

            if ($isPrimary) {
                $primaries++;
            }

            $id = is_array($image) ? ($image['id'] ?? null) : null;

            $rows[] = [
                'id' => $id === null || $id === '' ? null : (int) $id,
                'url' => $url,
                'is_primary' => $isPrimary,
            ];
        }

        if ($rows === []) {
            throw new UserError("Add at least one photo to {$label}.");
        }

        if ($primaries === 0) {
            $rows[0]['is_primary'] = true;
        } else {
            // Only the first marked photo stays primary.
            $kept = false;

            foreach ($rows as $index => $row) {
                if (! $row['is_primary']) {
                    continue;
                }

                if ($kept) {
                    $rows[$index]['is_primary'] = false;
                }

                $kept = true;
            }
        }

        return $rows;
    }

    /* --------------------------------------------------------------------- */
    /* Persistence                                                           */
    /* --------------------------------------------------------------------- */

    /**
     * Insert or update one variant, keeping its row so cart lines stay valid.
     *
     * @param  array<string, mixed>  $row
     */
    protected function persistVariant(Product $product, array $row): ProductVariant
    {
        $variant = $row['id'] !== null ? $this->existing->get($row['id']) : null;

        // The default flag is applied in one pass at the end, so the unique
        // index on (product_id, default_slot) never sees two defaults at once.
        $attributes = [
            'color_id' => $row['color_id'],
            'size_id' => $row['size_id'],
            'volume_id' => $row['volume_id'],
            'sku' => $row['sku'],
            'price' => $row['price'],
            'discount_price' => $row['discount_price'],
            'discount_starts_at' => $row['discount_starts_at'],
            'discount_ends_at' => $row['discount_ends_at'],
            'stock' => $row['stock'],
            'is_active' => $row['is_active'],
            'is_default' => false,
        ];

        if ($variant) {
            $variant->fill($attributes)->save();

            return $variant;
        }

        /** @var ProductVariant $created */
        $created = $product->variants()->create($attributes);

        return $created;
    }

    /**
     * Bring a variant's gallery in line with the submitted one.
     *
     * Existing photos are matched by id and updated in place (so `sort_order`
     * follows the order of the payload); a row without an id is inserted; a
     * stored photo left out of the payload is deleted. Deleting the row also
     * deletes its file — `VariantImage` does that on `deleting` — so trimming a
     * gallery never leaves orphaned uploads behind.
     *
     * @param  array<int, array{id: int|null, url: string, is_primary: bool}>  $images
     */
    protected function syncImages(ProductVariant $variant, array $images): void
    {
        /** @var Collection<int, VariantImage> $existing */
        $existing = $variant->images()->get()->keyBy('id');

        $kept = [];

        foreach ($images as $index => $image) {
            $stored = $image['id'] === null ? null : $existing->get($image['id']);

            if ($stored) {
                $stored->fill([
                    'url' => $image['url'],
                    'sort_order' => $index,
                    'is_primary' => $image['is_primary'],
                ])->save();

                $kept[] = $stored->id;

                continue;
            }

            $created = VariantImage::create([
                'product_variant_id' => $variant->id,
                'url' => $image['url'],
                'sort_order' => $index,
                'is_primary' => $image['is_primary'],
            ]);

            $kept[] = $created->id;
        }

        // Anything the payload dropped — including an id that belonged to another
        // variant — is deleted, which also forgets its stored file.
        $existing
            ->reject(fn (VariantImage $image): bool => in_array($image->id, $kept, true))
            ->each(fn (VariantImage $image) => $image->delete());

        $variant->unsetRelation('images');
    }

    /**
     * Remove the variants the payload no longer contains.
     *
     * A variant that already appears on an order is only deactivated: deleting
     * it would break the order line's foreign key and lose the history.
     *
     * The whole sweep may empty the product for a moment — the new rows are
     * inserted straight after — so the "last variant" model guard is lifted here;
     * `sync` has already refused an empty payload.
     *
     * @param  array<int, array<string, mixed>>  $rows  payload rows carrying `id`
     */
    protected function removeMissing(Product $product, array $rows): void
    {
        $kept = array_values(array_filter(array_map(
            static fn (array $row): ?int => $row['id'] === null ? null : (int) $row['id'],
            $rows
        )));

        $stale = ProductVariant::query()
            ->where('product_id', $product->id)
            ->when($kept !== [], fn ($query) => $query->whereKeyNot($kept))
            ->get();

        ProductVariant::withoutLastVariantGuard(function () use ($stale): void {
            foreach ($stale as $variant) {
                if ($variant->hasOrders()) {
                    $variant->forceFill(['is_active' => false, 'is_default' => false])->save();

                    continue;
                }

                $variant->delete();
            }
        });
    }

    /**
     * Exactly one variant is the default, and it is a buyable one.
     *
     * The admin's choice wins, but it can never point at an inactive variant: an
     * inactive row would give the product page an "unavailable" default and lose
     * the price it is supposed to open on. When nothing usable is marked, a
     * single variant is forced to be the default and a multi variant product
     * falls back to the first in stock, then the first active one.
     *
     * @param  array<int, array<string, mixed>>  $rows  rows carrying `variant`
     */
    protected function applyDefault(Product $product, array $rows): void
    {
        $variants = $product->variants()->get()->keyBy('id');

        if ($variants->isEmpty()) {
            return;
        }

        $chosenId = null;

        foreach ($rows as $row) {
            if (($row['is_default'] ?? false) && ($row['variant']->is_active ?? false)) {
                $chosenId = $row['variant']->id;
            }
        }

        if ($chosenId === null) {
            $active = $variants->filter(
                fn (ProductVariant $variant): bool => $variant->is_active
            );

            $chosenId = $active->firstWhere('in_stock', true)?->id
                ?? $active->first()?->id
                ?? $variants->first()->id;
        }

        foreach ($variants as $variant) {
            $shouldBeDefault = $variant->id === $chosenId;

            if ($variant->is_default !== $shouldBeDefault) {
                $variant->forceFill(['is_default' => $shouldBeDefault])->save();
            }
        }
    }

    /**
     * Resolve a master list id to its model, rejecting unknown ids.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    protected function resolve(string $class, mixed $id, string $label): ?object
    {
        if ($id === null || $id === '') {
            return null;
        }

        $model = $class::find((int) $id);

        if (! $model) {
            throw new UserError("That {$label} is no longer available.");
        }

        return $model;
    }
}
