<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database level safety for the money rule.
 *
 * A discount is either absent or a real reduction, so `discount_price` must be
 * strictly below `price`. The backend validates this long before the insert
 * (ProductVariantService), but a second line of defence in the schema means a
 * raw query or a future write path cannot create "a discount that costs more".
 *
 * MySQL and Postgres can add a check to an existing table. SQLite cannot without
 * rebuilding it, so there the rule stays enforced in the model and the service
 * only — the same trade-off the one-default index already makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement(
            'ALTER TABLE product_variants ADD CONSTRAINT chk_variant_discount '
            .'CHECK (discount_price IS NULL OR discount_price < price)'
        );
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT chk_variant_discount');
    }
};
