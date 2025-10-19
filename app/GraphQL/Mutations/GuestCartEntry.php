<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\CartItem;
use App\Models\GuestCartItem;
use Nodesol\LaraQL\Attributes\Mutation;


#[Mutation(
    name:'',
    query:'@field(resolver:"GuestCartEntry")',
    inputs:[
        'cart_id'=>'Int! @rules(apply:["required"])'
    ],
    return_type:'String!',
    directives:['@guard']
)]
class GuestCartEntry
{
    public function __invoke(null $root, array $args)
    {
        $cartItems = GuestCartItem::whereGuestCartId($args['cart_id'])->get();
        $user = auth()->user();
        $cartId = $user->cart->id;
        foreach ($cartItems as $item) {
            CartItem::create([
                'cart_id'=>$cartId,
                'product_id' => $item->product_id,
                'quantity'   => $item->quantity
            ]);
        }

        // Delete all guest items for that cart
        GuestCartItem::whereGuestCartId($args['cart_id'])->delete();

        return "Cart items transferred successfully.";

        
    }
}
