<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fabric types (the "Fabric Type" filter of the clothing catalogue).
 *
 * Managed once under Admin → Fabric types, then picked when building a clothing
 * product — exactly like colours, sizes and brands, so adding a fabric in one
 * place makes it available on every product and in the storefront filter.
 *
 * Perfume never carries one: `products.fabric_type_id` stays NULL for perfume,
 * which is why the fragrance page hides this facet entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabric_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_types');
    }
};
