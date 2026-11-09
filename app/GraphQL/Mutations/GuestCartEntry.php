<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\GuestCart;
use App\Models\GuestCartItem;
use App\Services\CartService;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

#[Mutation(
    name: '',
    query: '@field(resolver:"GuestCartEntry") @guard',
    inputs: [
        'cart_id' => 'Int! @rules(apply:["required"])'
    ],
    return_type: 'String!',
)]
class GuestCartEntry
{
    public function __construct(private readonly CartService $cart) {}

    public function __invoke(null $root, array $args)
    {
        $guestCart = GuestCart::find($args['cart_id']);

        if (! $guestCart) {
            throw new UserError('This guest cart no longer exists.');
        }

        $lines = GuestCartItem::whereGuestCartId($guestCart->id)
            ->get()
            ->map(fn (GuestCartItem $item): array => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ])
            ->all();

        $this->cart->mergeGuest(auth()->user(), $lines);

        GuestCartItem::whereGuestCartId($guestCart->id)->delete();

        return 'Cart items transferred successfully.';
    }
}
