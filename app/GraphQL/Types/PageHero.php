<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * The opening block of one storefront page, as the SPA renders it.
 *
 * `hero_image_url` is the resolved absolute URL (MediaService), while
 * `hero_image` stays the raw stored path so the admin form can re-save what it
 * loaded without rewriting a URL that may have been built from the disk.
 */
#[Type(
    name: 'PageHero',
    columns: [
        'page' => 'String!',
        'badge' => 'String',
        'title' => 'String!',
        'subtitle' => 'String',
        'hero_image' => 'String',
        'hero_image_url' => 'String',
        'cta_label' => 'String',
        'cta_url' => 'String',
    ],
)]
class PageHero
{
    public function __construct(
        public string $page,
        public ?string $badge,
        public string $title,
        public ?string $subtitle = null,
        public ?string $hero_image = null,
        public string $hero_image_url = '',
        public ?string $cta_label = null,
        public ?string $cta_url = null,
    ) {}
}
