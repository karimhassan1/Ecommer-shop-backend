<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog product.
 *
 * A product deliberately carries **no price and no image**: both live on its
 * variants. It only describes what the item is — its type, name, description
 * and, for clothing, the fabric and fit copy shown on the product page.
 *
 * LaraQL introspects the live table when it builds the GraphQL schema, so any
 * column kept here becomes an API field. That is why everything that belongs to
 * a variant (price, discount, stock, photos) is *not* defined here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // "clothing" (colour + size variants) or "perfume" (volume variants).
            $table->enum('type', ['clothing', 'perfume'])->default('clothing');

            $table->string('name');
            $table->text('description')->nullable();

            // Clothing only: the two detail blocks shown under the gallery.
            $table->text('fabric')->nullable();
            $table->text('fit_details')->nullable();

            $table->boolean('is_active')->default(true);

            // Denormalised review aggregates, read on every product card.
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
