<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postal codes are optional in Pakistan.
 *
 * The original orders table declared `postal_code` as a required integer, which
 * meant an order with no postal code (very common outside the big cities) failed
 * at insert time with a database error the customer could not act on. It is a
 * free form string now: some codes have leading zeros, and many addresses have
 * none at all. Validation happens in PlaceOrder instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('postal_code', 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('postal_code', 10)->nullable(false)->change();
        });
    }
};
