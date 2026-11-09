<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Customers may always read and create their own orders; staff access is
 * governed by `order.*` permissions.
 */
class OrderPolicy extends PermissionPolicy
{
    protected string $resource = 'order';

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ?Order $order = null): bool
    {
        if ($order && $order->user_id === $user->id) {
            return true;
        }

        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * A seller may only move an order that contains one of their products.
     */
    public function updateStatus(User $user, ?Order $order = null): bool
    {
        if ($order && $user->isSeller() && ! $this->containsSellerProduct($order, $user)) {
            return false;
        }

        return $this->allows($user, 'update-status');
    }

    /** Does this order include at least one product owned by the seller? */
    protected function containsSellerProduct(Order $order, User $user): bool
    {
        return $order->items()
            ->whereHas('product', fn ($product) => $product->where('seller_id', $user->id))
            ->exists();
    }
}
