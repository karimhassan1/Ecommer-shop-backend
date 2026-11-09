<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\ContactMessage;
use App\Services\MailService;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Public endpoint used by the storefront contact form.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SubmitContactMessage")',
    inputs: [
        'name' => 'String! @rules(apply:["required","max:120"])',
        'email' => 'String! @rules(apply:["required","email","max:180"])',
        'subject' => 'String! @rules(apply:["required","max:180"])',
        'message' => 'String! @rules(apply:["required","max:4000"])',
    ],
    return_type: 'ContactMessage!'
)]
class SubmitContactMessage
{
    public function __construct(private readonly MailService $mail) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $contact = ContactMessage::create([
            'name' => trim((string) $args['name']),
            'email' => strtolower(trim((string) $args['email'])),
            'subject' => trim((string) $args['subject']),
            'message' => trim((string) $args['message']),
            'is_read' => false,
        ]);

        // Notify the shop owner without blocking the customer.
        $this->mail->sendContactNotification($contact);

        return $contact;
    }
}
