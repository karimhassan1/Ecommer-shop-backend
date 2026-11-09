<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Keep the components of the total so an order remains readable
            // after the shipping fee or a product price changes.
            $table->decimal('subtotal', 10, 2)->default(0)->after('total_amount');
            $table->decimal('shipping_amount', 10, 2)->default(0)->after('subtotal');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total_amount', 10, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'shipping_amount']);
        });
    }
};
