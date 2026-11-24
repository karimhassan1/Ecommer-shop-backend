<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Category;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Every category with the number of products filed under it, for the admin
 * management screen.
 *
 * Unlike `CategoryList` (which the storefront uses for navigation) this is
 * staff-only and includes categories that hold no products yet.
 */
#[Query(
    name: 'AdminCategories',
    query: '@field(resolver:"AdminCategories") @guard @permission(require: ["category.view"])',
    filters: [
        'type: String',
        'search: String',
    ],
    return_type: '[Category!]!',
)]
class AdminCategories
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $search = trim((string) ($args['search'] ?? ''));

        $user = $this->requireAdmin();

        return Category::query()
            ->withCount('products')
            // A seller only files their own products under a category, so the
            // screen lists the categories they actually use. Administrators and
            // other staff still see the whole taxonomy.
            ->when(
                $user->isSeller(),
                fn ($query) => $query->whereHas(
                    'products',
                    fn ($product) => $product->where('seller_id', $user->id)
                )
            )
            ->when(
                ! empty($args['type']),
                fn ($query) => $query->where('type', $args['type'])
            )
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.strtolower($search).'%';

                $query->where(function ($nested) use ($term): void {
                    $nested->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(slug, \'\')) like ?', [$term]);
                });
            })
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
