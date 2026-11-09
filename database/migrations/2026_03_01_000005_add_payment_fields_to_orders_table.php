<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // unpaid | paid | refunded
            $table->string('payment_status')->default('unpaid')->after('payment_method');

            // Extra charge applied when the customer pays cash on delivery.
            $table->decimal('cod_fee', 12, 2)->default(0)->after('shipping_amount');

            // Who receives the parcel, useful for courier booking.
            $table->string('courier')->nullable()->after('tracking_number');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal', 12, 2)->default(0)->change();
            $table->decimal('shipping_amount', 12, 2)->default(0)->change();
            $table->decimal('total_amount', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'cod_fee', 'courier']);
        });
    }
};
