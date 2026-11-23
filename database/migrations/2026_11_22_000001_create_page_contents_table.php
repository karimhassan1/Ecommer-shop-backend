<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable copy and artwork for every storefront page.
 *
 * The marketing pages (home, the three category pages, about, contact, cart and
 * checkout) all share one hero block: a main picture, a badge, a title, a
 * subtitle and one call to action. Storing it here means an administrator can
 * change the picture a page opens with from the panel, without a deploy.
 *
 * `page` is the stable key the storefront asks for ("home", "fragrances", ...),
 * so adding a page needs no schema change — only a new default in
 * App\Models\PageContent::PAGES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_contents', function (Blueprint $table) {
            $table->id();
            $table->string('page')->unique();
            $table->string('badge')->nullable();
            $table->string('title');
            $table->text('subtitle')->nullable();
            // Stored path on the configured media disk, or an absolute URL.
            $table->string('hero_image')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_contents');
    }
};
