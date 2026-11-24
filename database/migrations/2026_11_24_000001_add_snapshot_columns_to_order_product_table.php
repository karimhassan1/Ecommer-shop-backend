<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze what a customer actually bought.
 *
 * An order line already stored the quantity, the unit price paid and the
 * colour/size labels. That is not enough to read an old order back once the
 * catalogue moves on: renaming a product, changing its price, running a discount
 * or deleting the variant would silently rewrite history.
 *
 * These columns give every line its own copy of the product name, its type and
 * SKU, plus the list price and the discount that were live at checkout — so the
 * order page, the invoice mail and the seller's packing list always describe the
 * sale as it happened, never as the catalogue looks today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_product', function (Blueprint $table): void {
            $table->string('product_name')->nullable()->after('product_variant_id');
            $table->string('product_type')->nullable()->after('product_name');
            $table->string('sku')->nullable()->after('product_type');

            // The regular (non discounted) price and the money taken off it.
            // `price` stays the amount the customer actually paid per unit.
            $table->decimal('list_price', 10, 2)->nullable()->after('quantity');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('list_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_product', function (Blueprint $table): void {
            $table->dropColumn([
                'product_name',
                'product_type',
                'sku',
                'list_price',
                'discount_amount',
            ]);
        });
    }
};
