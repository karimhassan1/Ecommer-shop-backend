<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Review;
use App\GraphQL\Support\AuthorizesAdmin;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: edit or moderate any review. Every input is optional so the admin
 * panel can toggle approval or fix a typo without resubmitting the whole object.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"UpdateReview") @guard @permission(require: ["review.update"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'rating' => 'Int @rules(apply:["integer","min:1","max:5"])',
        'title' => 'String @rules(apply:["max:120"])',
        'body' => 'String @rules(apply:["min:10","max:2000"])',
        'is_approved' => 'Boolean',
    ],
    return_type: 'Review!',
)]
class UpdateReview
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var Review|null $review */
        $review = Review::find($args['id']);

        if (! $review) {
            throw new UserError('That review could not be found.');
        }

        $payload = array_filter(
            [
                'rating' => $args['rating'] ?? null,
                'title' => $args['title'] ?? null,
                'body' => $args['body'] ?? null,
                'is_approved' => $args['is_approved'] ?? null,
            ],
            static fn ($value): bool => $value !== null
        );

        $review->update($payload);

        return $review->fresh('user');
    }
}
