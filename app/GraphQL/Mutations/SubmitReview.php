<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * A signed in customer may review a product they have actually ordered.
 *
 * A customer may leave several reviews for the same product (for example after
 * ordering it again in another colour). Passing `review_id` edits one of their
 * existing reviews instead of adding a new one.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SubmitReview") @guard',
    inputs: [
        'product_id' => 'ID! @rules(apply:["required"])',
        'review_id' => 'ID',
        'rating' => 'Int! @rules(apply:["required","integer","min:1","max:5"])',
        'title' => 'String @rules(apply:["max:120"])',
        'body' => 'String! @rules(apply:["required","min:10","max:2000"])',
    ],
    return_type: 'Review!',
)]
class SubmitReview
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = $this->requireAuth();

        /** @var Product|null $product */
        $product = Product::find($args['product_id']);

        if (! $product) {
            throw new UserError('That product could not be found.');
        }

        $purchased = OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id))
            ->exists();

        if (! $purchased) {
            throw new UserError('You can only review products you have ordered.');
        }

        $payload = [
            'rating' => (int) $args['rating'],
            'title' => isset($args['title']) ? trim((string) $args['title']) : null,
            'body' => trim((string) $args['body']),
            // New or edited reviews return to moderation.
            'is_approved' => false,
        ];

        // Editing one of the customer's own existing reviews.
        if (! empty($args['review_id'])) {
            /** @var Review|null $review */
            $review = Review::query()
                ->whereKey($args['review_id'])
                ->where('user_id', $user->id)
                ->first();

            if (! $review) {
                throw new UserError('That review could not be found.');
            }

            $review->update($payload);

            return $review->fresh('user');
        }

        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            ...$payload,
        ]);

        return $review->fresh('user');
    }
}
