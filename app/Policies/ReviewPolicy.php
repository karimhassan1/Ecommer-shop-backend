<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Customers moderate their own reviews; staff use `review.*` permissions.
 */
class ReviewPolicy extends PermissionPolicy
{
    protected string $resource = 'review';

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, ?Review $review = null): bool
    {
        if ($review && $review->user_id === $user->id) {
            return true;
        }

        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ?Review $review = null): bool
    {
        if ($review && $review->user_id === $user->id) {
            return true;
        }

        return $this->allows($user, 'update');
    }

    public function delete(User $user, ?Review $review = null): bool
    {
        if ($review && $review->user_id === $user->id) {
            return true;
        }

        return $this->allows($user, 'delete');
    }

    public function approve(User $user): bool
    {
        return $this->allows($user, 'approve');
    }
}
