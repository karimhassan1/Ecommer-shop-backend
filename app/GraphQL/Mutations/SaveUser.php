<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\User;
use App\Services\MailService;
use Database\Seeders\RoleSeeder;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Hash;
use Nodesol\LaraQL\Attributes\Mutation;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin only: create a new account, or edit an existing one.
 *
 * One mutation covers both because the admin form is the same: the only
 * difference is whether `id` was sent. Everything an administrator needs to
 * bring a colleague on board lives here — name, email, phone, password and the
 * roles that decide what they can reach in the panel:
 *
 *   SaveUser(name: "Ayesha", email: "…", password: "…", roles: ["catalog-manager"])
 *
 * Roles are optional, so the same mutation also creates ordinary customers.
 * Only roles that actually exist are accepted; an unknown role name fails the
 * whole request instead of silently granting nothing.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SaveUser") @guard @permission(require: ["user.create", "user.update"])',
    inputs: [
        'id' => 'ID',
        'name' => 'String! @rules(apply:["required","max:120"])',
        'email' => 'String! @rules(apply:["required","email","max:180"])',
        'phone' => 'String @rules(apply:["max:30"])',
        'password' => 'String @rules(apply:["nullable","min:8"])',
        'roles' => '[String!]',
        'is_active' => 'Boolean',
    ],
    return_type: 'User!',
)]
class SaveUser
{
    use AuthorizesAdmin;

    public function __construct(private readonly MailService $mail) {}

    /** @param array<string, mixed> $args */
    public function __invoke($root, array $args)
    {
        $actor = $this->requireAuth();
        $id = $args['id'] ?? null;

        /** @var User|null $user */
        $user = $id ? User::find($id) : null;

        if ($id && ! $user) {
            throw new UserError('That account could not be found.');
        }

        // A create needs `user.create`, an edit needs `user.update`.
        $ability = $user ? 'user.update' : 'user.create';

        if (! $actor->can($ability)) {
            throw new UserError('You are not allowed to perform this action.');
        }

        $email = strtolower(trim((string) $args['email']));

        // The column is unique; checking explicitly gives a readable message
        // and ignores the row being edited.
        $taken = User::query()
            ->where('email', $email)
            ->when($user, fn ($query) => $query->whereKeyNot($user->id))
            ->exists();

        if ($taken) {
            throw new UserError('That email address is already registered.');
        }

        $roles = $this->validatedRoles($args['roles'] ?? null);

        // Nobody may remove their own panel access, which would lock them out.
        if ($user && $user->id === $actor->id && $user->isStaff() && $roles === []) {
            throw new UserError('You cannot remove your own admin access.');
        }

        $isNew = $user === null;

        $user ??= new User();

        $password = isset($args['password']) && $args['password'] !== ''
            ? (string) $args['password']
            : null;

        $user->fill([
            'name' => trim((string) $args['name']),
            'email' => $email,
            'phone' => isset($args['phone']) && trim((string) $args['phone']) !== ''
                ? trim((string) $args['phone'])
                : null,
        ]);

        if ($password !== null) {
            $user->password = Hash::make($password);
        }

        if ($isNew) {
            // Staff created from the panel do not need to verify: an
            // administrator vouched for them.
            $user->email_verified_at = now();
            $user->is_admin = in_array(RoleSeeder::SUPER_ADMIN, $roles, true);
        }

        $user->save();

        if (array_key_exists('is_active', $args) && $args['is_active'] !== null) {
            $user->forceFill(['is_active' => (bool) $args['is_active']])->save();
        }

        if ($isNew) {
            $user->cartOrCreate();
        }

        if (array_key_exists('roles', $args) && $args['roles'] !== null) {
            $user->syncRoles($roles);

            // Only super-admin implies the legacy `is_admin` flag, otherwise
            // the super-admin fallback would give every role every permission.
            $user->forceFill([
                'is_admin' => $user->hasRole(RoleSeeder::SUPER_ADMIN),
            ])->save();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        // A brand new account gets the same welcome mail as a signup.
        if ($isNew) {
            $this->mail->sendWelcome($user);
        }

        return $user->fresh();
    }

    /**
     * Role names that exist, or a helpful error.
     *
     * @param  array<int, mixed>|null  $roles
     * @return array<int, string>
     */
    protected function validatedRoles(?array $roles): array
    {
        if ($roles === null) {
            return [];
        }

        $names = array_values(array_unique(array_filter(
            array_map(static fn ($role): string => trim((string) $role), $roles),
            static fn (string $role): bool => $role !== '',
        )));

        if ($names === []) {
            return [];
        }

        $existing = Role::query()
            ->where('guard_name', 'sanctum')
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        $unknown = array_values(array_diff($names, $existing));

        if ($unknown !== []) {
            throw new UserError(
                'Unknown role: '.implode(', ', $unknown).'. Create it under Roles first.'
            );
        }

        return $names;
    }
}
