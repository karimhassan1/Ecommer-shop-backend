<?php

namespace Database\Seeders;

use App\Models\PageContent;
use Illuminate\Database\Seeder;

/**
 * Persist the shipped hero copy for every storefront page.
 *
 * Rows are only a convenience for the admin editor: the API merges defaults in
 * either way, so this is safe to re-run and never overwrites an edited page
 * (each row is matched on its page key and left alone when it already exists).
 */
class PageContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PageContent::PAGES as $page => $defaults) {
            PageContent::query()->firstOrCreate(['page' => $page], [
                'badge' => $defaults['badge'] ?: null,
                'title' => $defaults['title'],
                'subtitle' => $defaults['subtitle'] ?: null,
                'hero_image' => $defaults['hero_image'] ?: null,
                'cta_label' => $defaults['cta_label'] ?: null,
                'cta_url' => $defaults['cta_url'] ?: null,
            ]);
        }
    }
}
