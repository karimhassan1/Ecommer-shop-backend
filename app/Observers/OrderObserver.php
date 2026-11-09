<?php

namespace App\Observers;

use App\Models\Order;

/**
 * Order creation is handled by App\Services\CartService so that the whole
 * checkout (stock, totals, cart cleanup) happens inside one transaction.
 * The observer only keeps the pivot table tidy.
 */
class OrderObserver
{
    public function deleting(Order $order): void
    {
        $order->items()->delete();
    }
}
