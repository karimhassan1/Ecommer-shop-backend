<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\CartService;
use App\Services\MailService;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Place an order from the authenticated customer's cart.
 *
 * Shipping and any cash-on-delivery charge are computed server side from the
 * admin configured settings, never trusted from the client.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"PlaceOrder") @guard',
    inputs: [
        'address' => 'CheckoutAddressInput! @spread',
    ],
    return_type: 'Order!',
)]
class PlaceOrder
{
    public function __construct(
        private readonly CartService $cart,
        private readonly MailService $mail,
    ) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $provinces = config('shop.provinces', []);

        if (! array_key_exists((string) $args['province'], $provinces)) {
            throw new UserError('Please choose a valid province.');
        }

        $city = (string) $args['city'];

        if (! in_array($city, $provinces[$args['province']] ?? [], true)) {
            throw new UserError('Please choose a city inside the selected province.');
        }

        $order = $this->cart->checkout(auth()->user(), $args);

        // The customer gets a receipt and every seller whose products are in
        // the basket is notified — see MailService::sendOrderConfirmation.
        $this->mail->sendOrderConfirmation($order);

        return $order;
    }
}
