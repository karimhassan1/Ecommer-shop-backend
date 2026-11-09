<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop wide master lists.
 *
 * Colours, sizes and volumes are managed once under Admin → Variant lists and
 * then picked when building a product, so adding a value in one place makes it
 * available on every product.
 *
 *   colours — clothing variants (with the hex shown as a swatch)
 *   sizes   — clothing variants (S, M, L, 38, 40 …)
 *   volumes — perfume variants (6 ml, 12 ml, 30 ml …)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hex_code', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->string('name');            // S, M, L, 38, 40 …
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('volumes', function (Blueprint $table) {
            $table->id();
            $table->string('label');           // "30 ml" (what the customer sees)
            $table->decimal('ml', 8, 2);       // numeric weight, for sorting
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('label');
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volumes');
        Schema::dropIfExists('sizes');
        Schema::dropIfExists('colors');
    }
};
