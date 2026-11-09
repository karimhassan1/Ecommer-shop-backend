<?php

namespace App\Observers;

use App\Models\Review;

/**
 * Keeps the denormalised rating aggregates on `products` in sync so product
 * listings never need to aggregate the reviews table.
 */
class ReviewObserver
{
    public function saved(Review $review): void
    {
        $this->recalculate($review);
    }

    public function deleted(Review $review): void
    {
        $this->recalculate($review);
    }

    protected function recalculate(Review $review): void
    {
        $product = $review->product;

        if (! $product) {
            return;
        }

        $approved = $product->reviews()->where('is_approved', true);

        $product->forceFill([
            'rating_count' => (clone $approved)->count(),
            'rating_average' => round((float) (clone $approved)->avg('rating'), 2),
        ])->saveQuietly();
    }
}
