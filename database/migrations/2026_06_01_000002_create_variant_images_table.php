<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos of a single variant.
 *
 * Images live here and nowhere else: the products table has no image column, so
 * the product page gallery is always exactly the selected variant's photos.
 *
 * `url` is the public path/URL the variant's photo is served from and
 * `is_primary` marks the "main pic" of that variant; `sort_order` is the order
 * the gallery shows them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();

            $table->string('url');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_variant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_images');
    }
};
