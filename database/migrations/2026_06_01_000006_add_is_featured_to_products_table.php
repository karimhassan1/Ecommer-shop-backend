<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Show on the home page" switch for a product.
 *
 * `is_featured` is purely a merchandising flag; it never affects whether a
 * product can be found or bought. The admin turns it on for the handful of
 * pieces the home page hero and "featured" carousel should show, and leaves it
 * off for everything else, which then only appears in the shop listing.
 *
 * Kept separate from `is_active` on purpose: `is_active` means "visible in the
 * store at all" (off = hidden from customers, rejected by the cart), while
 * `is_featured` only promotes a product to the home page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('is_active');

            // The home page asks for featured rows only, newest first.
            $table->index(['is_featured', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['is_featured', 'is_active']);
            $table->dropColumn('is_featured');
        });
    }
};
