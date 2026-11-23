<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\PageContent;
use App\Services\MediaService;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Every storefront page with its current hero, for the admin editor.
 *
 * The panel needs one row per page — including pages that have never been
 * edited — so it can show the effective picture and copy instead of an empty
 * form. Staff only: this is the editing surface, not the storefront's.
 */
#[Query(
    name: 'AdminPageHeroes',
    query: '@field(resolver:"AdminPageHeroes") @guard @permission(require: ["setting.view"])',
    filters: [],
    return_type: '[PageHero!]!',
)]
class AdminPageHeroes
{
    public function __construct(private readonly MediaService $media) {}

    /** @param array{} $args */
    public function __invoke($root, array $args): array
    {
        $stored = PageContent::query()->get()->keyBy('page');

        $rows = [];

        foreach (PageContent::pages() as $page) {
            $defaults = PageContent::defaults($page);
            $row = $stored->get($page);

            $raw = $row?->getRawOriginal('hero_image') ?? $defaults['hero_image'];

            $rows[] = [
                'page' => $page,
                'badge' => $row?->badge ?? $defaults['badge'],
                'title' => $row?->title ?? $defaults['title'],
                'subtitle' => $row?->subtitle ?? $defaults['subtitle'],
                'hero_image' => $raw === '' ? null : $raw,
                'hero_image_url' => $this->media->url($raw === '' ? null : $raw),
                'cta_label' => $row?->cta_label ?? $defaults['cta_label'],
                'cta_url' => $row?->cta_url ?? $defaults['cta_url'],
            ];
        }

        return $rows;
    }
}
