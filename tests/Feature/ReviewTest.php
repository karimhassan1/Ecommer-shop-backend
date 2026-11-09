<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Reviews: only buyers may review, new reviews wait for moderation and the
 * rating aggregates on the product stay in sync.
 */
class ReviewTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    /** Place an order for the product so the buyer is allowed to review it. */
    protected function buy(User $user, Product $product): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => '03001234567',
            'email' => $user->email,
            'province' => 'Punjab',
            'city' => 'Lahore',
            'street' => '12 Rose Avenue',
            'postal_code' => '54000',
            'status' => Order::STATUS_RECEIVED,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $this->defaultVariant($product)->id,
            'quantity' => 1,
            'price' => 6500,
        ]);

        return $order;
    }

    /** @return array<string, mixed> */
    protected function submit(Product $product, array $variables = []): array
    {
        return array_merge([
            'product_id' => (string) $product->id,
            'rating' => 5,
            'title' => 'Beautifully made',
            'body' => 'The silk drapes perfectly and the hem is finished by hand.',
        ], $variables);
    }

    /** The mutation a shopper's review form sends. */
    protected function submitMutation(): string
    {
        return <<<'GRAPHQL'
            mutation SubmitReview(
                $product_id: ID!
                $review_id: ID
                $rating: Int!
                $title: String
                $body: String!
            ) {
                SubmitReview(
                    product_id: $product_id
                    review_id: $review_id
                    rating: $rating
                    title: $title
                    body: $body
                ) {
                    id rating title is_approved author_name
                }
            }
        GRAPHQL;
    }

    public function test_a_customer_can_review_a_product_they_bought(): void
    {
        $customer = User::factory()->create();
        $product = $this->clothingProduct();

        $this->buy($customer, $product);

        $this->actingAs($customer)
            ->graphQL($this->submitMutation(), $this->submit($product))
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SubmitReview.rating', 5)
            // New reviews wait for moderation.
            ->assertJsonPath('data.SubmitReview.is_approved', false)
            ->assertJsonPath('data.SubmitReview.author_name', $customer->name);
    }

    public function test_a_customer_cannot_review_a_product_they_never_ordered(): void
    {
        $product = $this->clothingProduct();

        $this->actingAs(User::factory()->create())
            ->graphQL($this->submitMutation(), $this->submit($product))
            ->assertGraphQLErrorMessage('You can only review products you have ordered.');
    }

    public function test_the_rating_is_validated_between_one_and_five(): void
    {
        $customer = User::factory()->create();
        $product = $this->clothingProduct();
        $this->buy($customer, $product);

        $this->actingAs($customer)
            ->graphQL($this->submitMutation(), $this->submit($product, ['rating' => 6]))
            ->assertJsonPath('errors.0.extensions.validation', function (array $validation): bool {
                return array_key_exists('rating', $validation);
            });
    }

    public function test_unapproved_reviews_are_hidden_from_the_storefront(): void
    {
        $customer = User::factory()->create();
        $product = $this->clothingProduct();
        $this->buy($customer, $product);

        $this->actingAs($customer)
            ->graphQL($this->submitMutation(), $this->submit($product))
            ->assertJsonMissingPath('errors');

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID!) {
                ProductReviews(product_id: $id) { paginatorInfo { total } }
            }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.ProductReviews.paginatorInfo.total', 0);
    }

    public function test_an_approved_review_updates_the_product_rating(): void
    {
        $customer = User::factory()->create();
        $product = $this->clothingProduct();
        $this->buy($customer, $product);

        Review::create([
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'rating' => 4,
            'title' => 'Lovely',
            'body' => 'Lovely weight cotton that survived several washes.',
            'is_approved' => true,
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID!) { product(id: $id) { rating_average rating_count } }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.product.rating_average', 4)
            ->assertJsonPath('data.product.rating_count', 1);
    }

    public function test_a_customer_can_leave_several_reviews_for_one_product(): void
    {
        $customer = User::factory()->create();
        $product = $this->clothingProduct();
        $this->buy($customer, $product);

        foreach ([5, 4] as $rating) {
            $this->actingAs($customer)
                ->graphQL($this->submitMutation(), $this->submit($product, ['rating' => $rating]))
                ->assertJsonMissingPath('errors');
        }

        $this->assertSame(
            2,
            Review::where('product_id', $product->id)->where('user_id', $customer->id)->count()
        );
    }

    public function test_passing_a_review_id_edits_the_existing_review(): void
    {
        $customer = User::factory()->create();
        $product = $this->clothingProduct();
        $this->buy($customer, $product);

        $reviewId = $this->actingAs($customer)
            ->graphQL($this->submitMutation(), $this->submit($product))
            ->json('data.SubmitReview.id');

        $this->actingAs($customer)
            ->graphQL($this->submitMutation(), $this->submit($product, [
                'review_id' => (string) $reviewId,
                'rating' => 3,
                'title' => 'Changed my mind',
            ]))
            ->assertJsonPath('data.SubmitReview.id', (string) $reviewId)
            ->assertJsonPath('data.SubmitReview.rating', 3)
            ->assertJsonPath('data.SubmitReview.title', 'Changed my mind');

        $this->assertSame(1, Review::where('product_id', $product->id)->count());
    }

    public function test_an_admin_can_approve_a_pending_review(): void
    {
        $product = $this->clothingProduct();

        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 5,
            'body' => 'Waiting for approval before it shows up.',
            'is_approved' => false,
        ]);

        $this->actingAs($this->adminUser())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!, $input: ReviewInput!) {
                    updateReview(id: $id, input: $input) { id is_approved }
                }
            ', [
                'id' => (string) $review->id,
                'input' => [
                    'product_id' => $product->id,
                    'user_id' => $review->user_id,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'body' => $review->body,
                    'is_approved' => true,
                ],
            ])
            ->assertJsonPath('data.updateReview.is_approved', true);

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID!) { ProductReviews(product_id: $id) { paginatorInfo { total } } }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.ProductReviews.paginatorInfo.total', 1);
    }

    public function test_a_customer_cannot_approve_a_review(): void
    {
        $product = $this->clothingProduct();

        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 4,
            'body' => 'Moderation needs the review permission.',
            'is_approved' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->graphQL(/** @lang GraphQL */ '
                mutation ($id: ID!, $input: ReviewInput!) {
                    updateReview(id: $id, input: $input) { id }
                }
            ', [
                'id' => (string) $review->id,
                'input' => [
                    'product_id' => $product->id,
                    'user_id' => $review->user_id,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'body' => $review->body,
                    'is_approved' => true,
                ],
            ])
            ->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }
}
