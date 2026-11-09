<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Services\MailService;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: move an order through its lifecycle and notify the customer.
 * Authorization is enforced with the `manage-orders` gate.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"UpdateOrderStatus") @guard @permission(require: ["order.update-status"])',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'status' => 'String! @rules(apply:["required","in:pending,on-way,recieve"])',
        'tracking_number' => 'String @rules(apply:["max:120"])',
    ],
    return_type: 'Order!',
)]
class UpdateOrderStatus
{
    public function __construct(private readonly MailService $mail) {}

    public function __invoke($root, array $args)
    {
        /** @var Order|null $order */
        $order = Order::find($args['id']);

        if (! $order) {
            throw new UserError('That order could not be found.');
        }

        // A seller may only move an order that contains one of their products.
        if (! auth()->user()?->can('updateStatus', $order)) {
            throw new UserError('You can only update orders that contain your products.');
        }

        $previous = $order->status;

        $order->status = (string) $args['status'];

        if (isset($args['tracking_number'])) {
            $order->tracking_number = $args['tracking_number'];
        }

        if ($order->status === Order::STATUS_ON_WAY && ! $order->shipped_at) {
            $order->shipped_at = now();
        }

        if ($order->status === Order::STATUS_RECEIVED && ! $order->delivered_at) {
            $order->delivered_at = now();
        }

        $order->save();

        if ($previous !== $order->status) {
            $this->mail->sendOrderStatusChanged($order->fresh(), $previous);
        }

        return $order->fresh(['items', 'user']);
    }
}
