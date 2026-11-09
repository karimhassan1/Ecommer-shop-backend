<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database level safety for the variant rules.
 *
 * 1. No duplicate combinations per product.
 *    A plain composite unique index is enough on both MySQL and SQLite: rows
 *    whose identifying columns are NULL are treated as distinct, so the perfume
 *    index only restricts rows that actually have a volume, and the clothing
 *    index only restricts rows that actually have a colour.
 *
 * 2. Exactly one default variant per product.
 *    A partial ("WHERE is_default") unique index is Postgres only, so the same
 *    guarantee is expressed with a generated column that is 1 for the default
 *    row and NULL otherwise. The index then allows any number of NULLs but only
 *    one default per product. The column is hidden from the API on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'volume_id'], 'uq_perfume_variant');
            $table->unique(['product_id', 'color_id', 'size_id'], 'uq_cloth_variant');
        });

        $driver = Schema::getConnection()->getDriverName();

        Schema::table('product_variants', function (Blueprint $table) use ($driver): void {
            // Driver specific expression, both yield 1 for the default row.
            $expression = $driver === 'sqlite'
                ? '(case when is_default = 1 then 1 else null end)'
                : 'if(`is_default`, 1, null)';

            $table->unsignedTinyInteger('default_slot')
                ->nullable()
                ->virtualAs($expression);
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'default_slot'], 'one_default_per_product');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('one_default_per_product');
            $table->dropColumn('default_slot');
            $table->dropUnique('uq_perfume_variant');
            $table->dropUnique('uq_cloth_variant');
        });
    }
};
