<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Cart;
use App\Models\User;
use App\Services\MailService;
use App\Services\VerificationCodeService;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

#[Type(
    columns: [
        'token' => 'String!',
        'user' => 'User!',
        'message' => 'String!',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"Register")',
    inputs: [
        'name' => 'String! @rules(apply:["required","max:120"])',
        'phone' => 'String! @rules(apply:["required","max:30"])',
        'email' => 'String! @rules(apply:["required","email","unique:users,email"])',
        'password' => 'String! @rules(apply:["required","confirmed","min:8"])',
        'password_confirmation' => 'String! @rules(apply:["required","min:8"])'
    ]
)]
class Register
{
    public function __construct(
        private readonly MailService $mail,
        private readonly VerificationCodeService $codes,
    ) {}

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $user = User::create([
            'name' => trim((string) $args['name']),
            'phone' => trim((string) $args['phone']),
            'email' => Str::lower(trim((string) $args['email'])),
            'password' => (string) $args['password'],
            'is_admin' => false,
        ]);

        Cart::create(['user_id' => $user->id]);

        $token = $user->createToken('api')->plainTextToken;

        // Both emails are fire-and-forget, a mail outage never blocks signup.
        $this->mail->sendWelcome($user);
        $this->mail->sendEmailVerification($user);

        // The six digit code is the flow the SPA actually uses: the customer is
        // held on the verification screen until it is entered.
        $this->codes->send($user, enforceCooldown: false);

        return [
            'token' => $token,
            'user' => $user->fresh(),
            'message' => 'Registered successfully. Enter the 6 digit code we emailed you to verify your address.',
        ];
    }
}
