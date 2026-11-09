<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\ContactMessage;
use App\GraphQL\Support\AuthorizesAdmin;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Admin only: mark a contact message as read or unread.
 *
 * The auto generated `updateContactMessage` requires the whole input object,
 * which is awkward for a single boolean toggle.
 */
#[Type(
    columns: [
        'id' => 'ID!',
        'is_read' => 'Boolean!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"MarkContactMessageRead") @guard @permission',
    inputs: [
        'id' => 'ID! @rules(apply:["required"])',
        'is_read' => 'Boolean! = true',
    ],
    return_type: 'MarkContactMessageRead!',
)]
class MarkContactMessageRead
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        /** @var ContactMessage|null $message */
        $message = ContactMessage::find($args['id']);

        if (! $message) {
            throw new UserError('That message could not be found.');
        }

        $message->forceFill(['is_read' => (bool) ($args['is_read'] ?? true)])->save();

        return [
            'id' => $message->id,
            'is_read' => $message->is_read,
        ];
    }
}
