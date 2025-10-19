<?php

namespace App\Observers;

use App\Models\GuestCartItem;
use GraphQL\Error\UserError;

class GuestCartItemObserver
{
    /**
     * Handle the GuestCartItem "created" event.
     */
    public function created(GuestCartItem $guestCartItem): void
    {
        //
    }

    /**
     * Handle the GuestCartItem "creating" event.
     */
    public function creating(GuestCartItem $guestCartItem): void
    {
       $product_id = $guestCartItem->product_id;
       $cartId = $guestCartItem->guest_cart_id;

       $exist = GuestCartItem::whereGuestCartId($cartId)->whereProductId($product_id)->first();

       if($exist){
         throw new UserError('Product Already Exists in Cart if you want Update quantity');
       }
       
    }
    

    /**
     * Handle the GuestCartItem "updated" event.
     */
    public function updated(GuestCartItem $guestCartItem): void
    {
        //
    }

    /**
     * Handle the GuestCartItem "deleted" event.
     */
    public function deleted(GuestCartItem $guestCartItem): void
    {
        //
    }

    /**
     * Handle the GuestCartItem "restored" event.
     */
    public function restored(GuestCartItem $guestCartItem): void
    {
        //
    }

    /**
     * Handle the GuestCartItem "force deleted" event.
     */
    public function forceDeleted(GuestCartItem $guestCartItem): void
    {
        //
    }
}
