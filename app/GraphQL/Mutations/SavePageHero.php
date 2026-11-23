<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Queries\PageHero;
use App\Models\PageContent;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: change the opening block of one storefront page.
 *
 * This is the "main pic" switch the panel exposes. Only the fields that were
 * sent are written, so an editor can replace the picture without touching the
 * copy — and vice versa. `page` must be one of PageContent::PAGES, which keeps a
 * typo from silently creating a page nothing renders.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SavePageHero") @guard @permission(require: ["setting.update"])',
    inputs: [
        'page' => 'String! @rules(apply:["required","max:40"])',
        'badge' => 'String @rules(apply:["nullable","max:80"])',
        'title' => 'String @rules(apply:["nullable","max:160"])',
        'subtitle' => 'String @rules(apply:["nullable","max:500"])',
        'hero_image' => 'String @rules(apply:["nullable","max:2048"])',
        'cta_label' => 'String @rules(apply:["nullable","max:60"])',
        'cta_url' => 'String @rules(apply:["nullable","max:2048"])',
    ],
    return_type: 'PageHero!',
)]
class SavePageHero
{
    /** @param array<string, mixed> $args */
    public function __invoke($root, array $args)
    {
        $page = trim((string) $args['page']);

        if (! array_key_exists($page, PageContent::PAGES)) {
            throw new UserError("There is no \"{$page}\" page to edit.");
        }

        $defaults = PageContent::defaults($page);

        /** @var PageContent $row */
        $row = PageContent::query()->firstOrNew(['page' => $page]);

        // A brand new row starts from the shipped copy, so saving only the
        // picture does not blank out the title the storefront shows.
        if (! $row->exists) {
            $row->fill([
                'badge' => $defaults['badge'],
                'title' => $defaults['title'],
                'subtitle' => $defaults['subtitle'],
                'cta_label' => $defaults['cta_label'],
                'cta_url' => $defaults['cta_url'],
            ]);
        }

        foreach (['badge', 'subtitle', 'hero_image', 'cta_label', 'cta_url'] as $field) {
            if (! array_key_exists($field, $args) || $args[$field] === null) {
                continue;
            }

            $value = trim((string) $args[$field]);
            $row->{$field} = $value === '' ? null : $value;
        }

        // The title is what the page is called, so an empty one keeps the
        // previous value instead of leaving a headline-less hero.
        if (isset($args['title']) && trim((string) $args['title']) !== '') {
            $row->title = trim((string) $args['title']);
        }

        if (! $row->title) {
            $row->title = $defaults['title'];
        }

        $row->save();

        return app(PageHero::class)($root, ['page' => $page]);
    }
}
