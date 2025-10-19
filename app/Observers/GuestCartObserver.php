<?php

namespace App\Observers;

use App\Models\GuestCart;
use Illuminate\Support\Str;

class GuestCartObserver
{
    /**
     * Handle the GuestCart "created" event.
     */
    public function created(GuestCart $guestCart): void
    {
        //
    }
    public function creating(GuestCart $guestCart): void
    {
        $guestCart->token = Str::random(40);
    }

    /**
     * Handle the GuestCart "updated" event.
     */
    public function updated(GuestCart $guestCart): void
    {
        //
    }

    /**
     * Handle the GuestCart "deleted" event.
     */
    public function deleted(GuestCart $guestCart): void
    {
        //
    }

    /**
     * Handle the GuestCart "restored" event.
     */
    public function restored(GuestCart $guestCart): void
    {
        //
    }

    /**
     * Handle the GuestCart "force deleted" event.
     */
    public function forceDeleted(GuestCart $guestCart): void
    {
        //
    }
}
