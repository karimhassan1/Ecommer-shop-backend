<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Brand;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Admin only: delete a brand.
 *
 * Deleting a label never deletes the products carrying it; the pivot rows are
 * removed by the foreign key and the products simply become brand-less, which
 * is a state the model already allows.
 */
#[Type(columns: ['message' => 'String!', 'deleted_id' => 'ID'])]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"DeleteBrand") @guard @permission(require: ["brand.delete"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
    ],
)]
class DeleteBrand
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var Brand|null $brand */
        $brand = Brand::find($args['id']);

        if (! $brand) {
            throw new UserError('That brand could not be found.');
        }

        $this->requireAdmin();

        $id = $brand->id;

        // Detach explicitly so the products are kept even on a driver that
        // does not enforce the cascade.
        $brand->products()->detach();
        $brand->delete();

        return ['message' => 'Brand deleted successfully.', 'deleted_id' => $id];
    }
}
