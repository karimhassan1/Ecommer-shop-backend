<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellers own products.
 *
 * Every product belongs either to the shop itself (`seller_id` NULL, created by
 * a super admin) or to one seller account. A user holding the `seller` role
 * only ever lists, edits and fulfils the products they own, which is what makes
 * the admin panel "role based" for them.
 *
 * The column is nullable and cascades to NULL on delete so removing a seller
 * account never destroys catalogue history (orders keep pointing at the rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('seller_id')
                ->nullable()
                ->after('type')
                ->constrained('users')
                ->nullOnDelete();

            // The seller's own product list, and the seller order lookups.
            $table->index(['seller_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['seller_id', 'is_active']);
            $table->dropConstrainedForeignId('seller_id');
        });
    }
};
