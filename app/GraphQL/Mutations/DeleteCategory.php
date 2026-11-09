<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Category;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Admin only: delete an empty category.
 *
 * A category that still holds products is refused, because deleting it would
 * silently unfile those products.
 */
#[Type(columns: ['message' => 'String!', 'deleted_id' => 'ID'])]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"DeleteCategory") @guard @permission(require: ["category.delete"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
    ],
)]
class DeleteCategory
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var Category|null $category */
        $category = Category::find($args['id']);

        if (! $category) {
            throw new UserError('That category could not be found.');
        }

        $count = $category->products()->count();

        if ($count > 0) {
            throw new UserError(
                "Move the {$count} product(s) in this category elsewhere before deleting it."
            );
        }

        $id = $category->id;
        $category->delete();

        return ['message' => 'Category deleted successfully.', 'deleted_id' => $id];
    }
}
