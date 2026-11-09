<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Color;
use App\Models\Size;
use App\Models\Volume;
use Nodesol\LaraQL\Attributes\Query;
use Nodesol\LaraQL\Attributes\Type;

/**
 * The three master variant lists in one request.
 *
 * The admin product form needs all of them at once to build the colour/size or
 * volume pickers; the storefront uses the same data for its filters.
 */
#[Type(
    columns: [
        'colors' => '[Color!]!',
        'sizes' => '[Size!]!',
        'volumes' => '[Volume!]!',
    ]
)]
#[Query(
    name: 'VariantMasters',
    query: '@field(resolver:"VariantMasters")',
    filters: [],
)]
class VariantMasters
{
    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        return [
            'colors' => Color::query()
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
            'sizes' => Size::query()
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
            'volumes' => Volume::query()
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('ml')
                ->get(),
        ];
    }
}
