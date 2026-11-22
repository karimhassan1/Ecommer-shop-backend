<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Services\CartService;
use App\Services\MailService;
use App\Support\Address;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Place an order from the authenticated customer's cart.
 *
 * Shipping and any cash-on-delivery charge are computed server side from the
 * admin configured settings, never trusted from the client.
 *
 * The delivery details are validated here (not only in the browser) because the
 * province/city pair decides whether the parcel can actually be delivered:
 * "Lahore" is only a valid city when "Punjab" is the province.
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
        $data = $this->validatedAddress($args);

        $order = $this->cart->checkout(auth()->user(), $data);

        // The customer gets a receipt and every seller whose products are in
        // the basket is notified — see MailService::sendOrderConfirmation.
        $this->mail->sendOrderConfirmation($order);

        return $order;
    }

    /**
     * Normalise and validate the shipping details.
     *
     * Returns the cleaned values, so the order stores the canonical province
     * and city names no matter how the client spelled them.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function validatedAddress(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        $phone = trim((string) ($args['phone'] ?? ''));
        $email = trim((string) ($args['email'] ?? ''));
        $street = trim((string) ($args['street'] ?? ''));
        $postal = trim((string) ($args['postal_code'] ?? ''));

        if ($name === '') {
            throw new UserError('Please enter the receiver’s full name.');
        }

        // Digits only, so "0300 1234567" and "+92 300 1234567" are accepted.
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw new UserError('Please enter a valid Pakistani phone number (10 to 15 digits).');
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('Please enter a valid email address.');
        }

        $province = Address::canonicalProvince($args['province'] ?? null);

        if ($province === null) {
            throw new UserError('Please choose a valid province.');
        }

        $city = Address::canonicalCity($province, $args['city'] ?? null);

        if ($city === null) {
            throw new UserError('Please choose a city inside the selected province.');
        }

        if ($street === '') {
            throw new UserError('Please enter your street address.');
        }

        if (mb_strlen($street) > 255) {
            throw new UserError('The street address is too long.');
        }

        // Postal codes are optional: many rural addresses do not have one.
        if ($postal !== '' && ! preg_match('/^\d{4,6}$/', $postal)) {
            throw new UserError('A postal code must be 4 to 6 digits.');
        }

        $payment = (string) ($args['payment_method'] ?? '');

        return [
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'province' => $province,
            'city' => $city,
            'street' => $street,
            'postal_code' => $postal !== '' ? $postal : null,
            'notes' => isset($args['notes']) && trim((string) $args['notes']) !== ''
                ? trim((string) $args['notes'])
                : null,
            'payment_method' => $payment !== '' ? $payment : null,
        ];
    }
}

