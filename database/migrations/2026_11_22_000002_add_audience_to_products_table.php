<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which half of the shop a product is merchandised for.
 *
 * The storefront lists "Men's", "Women's" and "Unisex" alongside the product
 * type, so a jacket can appear under Jackets & Hoodies *and* under Women's
 * without being duplicated. It is a single column rather than a pivot because a
 * product belongs to exactly one audience.
 *
 * Existing rows stay visible with the `unisex` default, which keeps every
 * current listing on the shop untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('audience')->default('unisex')->after('type');

            $table->index(['type', 'audience']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['type', 'audience']);
            $table->dropColumn('audience');
        });
    }
};
