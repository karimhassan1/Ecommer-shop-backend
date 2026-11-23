<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Brands get a slug so the admin table can show a stable identifier and the
 * storefront can filter by one (`/?brand=atelier-noir`).
 *
 * Existing rows are backfilled from their name before the unique index is
 * added, so no install can fail on duplicate labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'slug')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('name');
            });
        }

        // Backfill, keeping the slugs unique even when two brands had the same
        // name before the column existed.
        $used = [];

        DB::table('brands')->orderBy('id')->each(function ($brand) use (&$used): void {
            $base = Str::slug((string) $brand->name) ?: 'brand';
            $slug = $base;
            $suffix = 2;

            while (in_array($slug, $used, true)) {
                $slug = $base.'-'.$suffix;
                $suffix++;
            }

            $used[] = $slug;

            DB::table('brands')->where('id', $brand->id)->update(['slug' => $slug]);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
