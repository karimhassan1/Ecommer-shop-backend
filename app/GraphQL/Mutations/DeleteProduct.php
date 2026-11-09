<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Product;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns_override: [
        'message' => 'String!',
        'deleted_id' => 'ID',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"DeleteProduct") @guard @permission(require: ["product.delete"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
    ],
)]
class DeleteProduct
{
    public function __invoke($root, array $args)
    {
        /** @var Product|null $product */
        $product = Product::find($args['id']);

        if (! $product) {
            throw new UserError('That product could not be found.');
        }

        // Row level half of the permission check: sellers delete only their own
        // products, staff and administrators delete anything.
        if (! auth()->user()?->can('delete', $product)) {
            throw new UserError('You can only delete products you sell.');
        }

        $id = $product->id;
        $product->delete();

        return [
            'message' => 'Product deleted successfully.',
            'deleted_id' => $id,
        ];
    }
}
