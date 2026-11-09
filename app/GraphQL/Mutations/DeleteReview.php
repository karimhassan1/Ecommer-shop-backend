<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Review;
use App\GraphQL\Support\AuthorizesAdmin;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Delete a review. A customer may delete their own review, an administrator
 * may delete any review.
 */
#[Type(
    columns: [
        'message' => 'String!',
        'deleted_id' => 'ID',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"DeleteReview") @guard',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
    ],
)]
class DeleteReview
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = $this->requireAuth();

        /** @var Review|null $review */
        $review = Review::find($args['id']);

        if (! $review) {
            throw new UserError('That review could not be found.');
        }

        if (! $user->isAdmin() && $review->user_id !== $user->id) {
            throw new UserError('You can only delete your own review.');
        }

        $id = $review->id;
        $review->delete();

        return [
            'message' => 'Review deleted successfully.',
            'deleted_id' => $id,
        ];
    }
}
