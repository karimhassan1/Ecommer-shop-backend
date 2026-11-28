<?php declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\FabricType;
use Nodesol\LaraQL\Attributes\Query;

/**
 * Every active fabric type with the number of products made of it.
 *
 * This is the same list the product form's "Fabric type" picker uses, so it
 * deliberately lists *every* active fabric, not only the ones something is
 * already made of: a fabric an administrator has just created must be pickable
 * on the very next product, exactly like a brand or a category.
 *
 * Only the *count* is narrowed for a seller (it reports the products they sell,
 * not the whole shop), because filtering the rows the same way made a brand new
 * fabric invisible to the person who created it — it has no products yet.
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

        // A retired fabric is hidden from the filter and from the picker, so it
        // is not offered here either.
        $countScope = fn ($query) => $user->isSeller()
            ? $query->where('products.seller_id', $user->id)
            : $query;

        return FabricType::query()
            ->where('is_active', true)
            ->withCount(['products' => $countScope])
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
