<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The attributes the storefront filter sidebar narrows a catalogue by.
 *
 *   fabric_type_id  — clothing only, the "Fabric Type" facet (a real record, so
 *                     it is managed as a list rather than typed per product)
 *   season          — "Summer / Winter / All Season"
 *   fabric_quality  — "Premium / Standard"
 *
 * All three are nullable: perfume carries none of them, and an existing product
 * saved before this migration simply keeps showing on the shop until an
 * administrator fills them in.
 *
 * `products.fabric` stays as it is — the free-text care/fibre detail shown on
 * the product page — because it describes the garment, while `fabric_type_id`
 * is the browsable category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('fabric_type_id')
                ->nullable()
                ->after('fabric')
                ->constrained('fabric_types')
                ->nullOnDelete();

            $table->string('season')->nullable()->after('fabric_type_id');
            $table->string('fabric_quality')->nullable()->after('season');

            $table->index(['type', 'fabric_type_id']);
            $table->index(['type', 'season']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['type', 'fabric_type_id']);
            $table->dropIndex(['type', 'season']);

            $table->dropConstrainedForeignId('fabric_type_id');
            $table->dropColumn(['season', 'fabric_quality']);
        });
    }
};
