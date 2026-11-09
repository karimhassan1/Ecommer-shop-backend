<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\User;
use App\Services\MailService;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: create another administrator or staff account.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"CreateAdminUser") @guard @permission',
    inputs: [
        'name' => 'String! @rules(apply:["required","max:120"])',
        'email' => 'String! @rules(apply:["required","email","unique:users,email"])',
        'phone' => 'String @rules(apply:["max:30"])',
        'password' => 'String! @rules(apply:["required","min:8"])',
    ],
    return_type: 'User!',
)]
class CreateAdminUser
{
    public function __construct(private readonly MailService $mail) {}

    public function __invoke($root, array $args)
    {
        $user = User::create([
            'name' => trim((string) $args['name']),
            'email' => strtolower(trim((string) $args['email'])),
            'phone' => $args['phone'] ?? null,
            'password' => (string) $args['password'],
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $user->cartOrCreate();

        $this->mail->sendWelcome($user);

        return $user->fresh();
    }
}
