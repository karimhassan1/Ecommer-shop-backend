<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Brand;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Every brand with the number of products carrying it, for the admin screen.
 *
 * Unlike the storefront's brand facet (which hides brands nothing is filed
 * under) this lists the whole table, so an administrator can see a label they
 * created but have not used yet.
 */
#[Query(
    name: 'AdminBrands',
    query: '@field(resolver:"AdminBrands") @guard @permission(require: ["brand.view"])',
    filters: [
        'search: String',
    ],
    return_type: '[Brand!]!',
)]
class AdminBrands
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $search = trim((string) ($args['search'] ?? ''));

        $this->requireAdmin();

        return Brand::query()
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.strtolower($search).'%';

                $query->where(function ($nested) use ($term): void {
                    $nested->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(slug, \'\')) like ?', [$term]);
                });
            })
            ->orderBy('name')
            ->get();
    }
}
