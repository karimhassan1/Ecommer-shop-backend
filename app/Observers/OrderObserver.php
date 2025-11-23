<?php

namespace App\Observers;

use App\Models\Order;
use GraphQL\Error\UserError;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        $user = auth()->user();
        $cartItems = $user->cart->cartItem()->with('product')->get();
        if(empty($cartItems)){
            throw new UserError('sorry your cart is empty');
        }
        $total_amount = 200;
        foreach($cartItems as $cartItem){
            $order->orderProudcts()->attach($order->id,['product_id'=>$cartItem->product_id,'quantity'=>$cartItem->quantity,'price'=>$cartItem->product->price]);
            $total_amount += $cartItem->product->price;
        }
        $order->update(['total_amount'=>$total_amount]);
        $user->cart->cartItem()->delete();
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        //
    }

    public function deleting(Order $order): void
    {
        $order->orderProudcts()->detach();
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        //
    }
}
