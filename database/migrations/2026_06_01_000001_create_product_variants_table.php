<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One buyable option of a product.
 *
 *   Clothing -> identified by colour_id + size_id  (from the colours/sizes lists)
 *   Perfume  -> identified by volume_id            (from the volumes list)
 *
 * Each variant owns its price, optional discount, stock and photo gallery, so
 * switching a combination on the product page can swap the price and gallery
 * without touching the product row.
 *
 * Cart lines and order lines point at a variant, which is the unit of stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Clothing only.
            $table->foreignId('color_id')->nullable()->constrained('colors')->nullOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();

            // Perfume only.
            $table->foreignId('volume_id')->nullable()->constrained('volumes')->nullOnDelete();

            $table->string('sku')->nullable()->unique();

            $table->decimal('price', 10, 2);

            // null means "no discount". When set it must be below the price.
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->timestamp('discount_starts_at')->nullable();
            $table->timestamp('discount_ends_at')->nullable();

            $table->integer('stock')->default(0);

            // The combination shown when a customer opens the product page.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
