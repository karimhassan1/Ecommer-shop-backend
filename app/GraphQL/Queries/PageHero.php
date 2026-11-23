<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\PageContent;
use App\Services\MediaService;
use Nodesol\LaraQL\Attributes\Query;

/**
 * The editable opening block of one storefront page.
 *
 * Public: the hero is marketing copy, so a signed out visitor has to see it too.
 * The stored overrides are merged over the defaults in `PageContent::PAGES`, so
 * a page that has never been edited still renders its shipped copy.
 */
#[Query(
    name: 'PageHero',
    query: '@field(resolver:"PageHero")',
    filters: [
        'page: String!',
    ],
    return_type: 'PageHero!',
)]
class PageHero
{
    public function __construct(private readonly MediaService $media) {}

    /** @param array{page: string} $args */
    public function __invoke($root, array $args): array
    {
        return $this->shape((string) $args['page']);
    }

    /**
     * One page's hero, defaults merged with whatever was saved.
     *
     * @return array<string, mixed>
     */
    protected function shape(string $page): array
    {
        $defaults = PageContent::defaults($page);
        $stored = PageContent::query()->where('page', $page)->first();

        $raw = $stored?->getRawOriginal('hero_image') ?? $defaults['hero_image'];

        return [
            'page' => $page,
            'badge' => $stored?->badge ?? $defaults['badge'],
            'title' => $stored?->title ?? $defaults['title'],
            'subtitle' => $stored?->subtitle ?? $defaults['subtitle'],
            'hero_image' => $raw === '' ? null : $raw,
            'hero_image_url' => $this->media->url($raw === '' ? null : $raw),
            'cta_label' => $stored?->cta_label ?? $defaults['cta_label'],
            'cta_url' => $stored?->cta_url ?? $defaults['cta_url'],
        ];
    }
}
