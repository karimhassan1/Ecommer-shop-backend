<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\FabricType;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Admin only: delete a fabric type.
 *
 * Deleting a fabric never deletes the products made of it. The foreign key is
 * `nullOnDelete`, so those products simply become "not listed under a fabric" —
 * a state the storefront already handles, since the facet is optional.
 */
#[Type(columns: ['message' => 'String!', 'deleted_id' => 'ID'])]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"DeleteFabricType") @guard @permission(require: ["product.delete"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
    ],
)]
class DeleteFabricType
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var FabricType|null $fabric */
        $fabric = FabricType::find($args['id']);

        if (! $fabric) {
            throw new UserError('That fabric type could not be found.');
        }

        $this->requireAdmin();

        $id = $fabric->id;

        // Detach explicitly so the products are kept even on a driver that does
        // not enforce the `nullOnDelete` rule.
        $fabric->products()->update(['fabric_type_id' => null]);
        $fabric->delete();

        return ['message' => 'Fabric type deleted successfully.', 'deleted_id' => $id];
    }
}
