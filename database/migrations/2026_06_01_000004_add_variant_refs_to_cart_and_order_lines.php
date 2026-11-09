<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart lines and order lines point at the variant that was chosen.
 *
 * The variant is the unit of stock, so a cart line for "Black / M" and a line
 * for "Black / L" are two different rows. Order lines additionally snapshot the
 * colour and size/volume labels so an old order stays readable after its
 * variant is renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->index(['cart_id', 'product_variant_id']);
        });

        Schema::table('order_product', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->nullOnDelete();

            // Snapshots: "Black" + "M" for clothing, "30 ml" for perfume.
            $table->string('color_name')->nullable()->after('product_variant_id');
            $table->string('size_label')->nullable()->after('color_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_product', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn(['color_name', 'size_label']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropIndex(['cart_id', 'product_variant_id']);
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
