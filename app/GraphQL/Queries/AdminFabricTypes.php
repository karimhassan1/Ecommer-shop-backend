<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\FabricType;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Every fabric type with the number of products made of it, for the admin
 * screen.
 *
 * Unlike the storefront facet (which hides fabrics nothing is made of) this
 * lists the whole table, so an administrator can see a fabric they created but
 * have not used yet.
 */
#[Query(
    name: 'AdminFabricTypes',
    query: '@field(resolver:"AdminFabricTypes") @guard @permission(require: ["product.view"])',
    filters: [
        'search: String',
    ],
    return_type: '[FabricType!]!',
)]
class AdminFabricTypes
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $search = trim((string) ($args['search'] ?? ''));

        $user = $this->requireAdmin();

        return FabricType::query()
            ->withCount('products')
            // A seller only trades their own catalogue, so the screen counts
            // *their* products. Staff and administrators see the whole shop.
            ->when(
                $user->isSeller(),
                fn ($query) => $query->whereHas(
                    'products',
                    fn ($product) => $product->where('seller_id', $user->id)
                )
            )
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.strtolower($search).'%';

                $query->where(function ($nested) use ($term): void {
                    $nested->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(slug, \'\')) like ?', [$term]);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
