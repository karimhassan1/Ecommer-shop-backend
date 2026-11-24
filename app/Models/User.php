<?php

namespace App\Models;

use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Nodesol\LaraQL\Attributes\Model;
use Spatie\Permission\Traits\HasRoles;

/**
 * Application user.
 *
 * Directives for the generated operations live in the `query` string (and not
 * in `directives`) because LaraQL merges all generated `extend type Query`
 * blocks, hoisting object level directives onto every field of the root type.
 */
#[Model(
    input_override: [
        'password' => 'String',
        'is_admin' => 'Boolean',
        'is_active' => 'Boolean',
    ],
    operations: [
        'query' => [
            'query' => '@guard @find',
        ],
        'query_collection' => [
            'query' => '@guard @permission(require: ["user.view"]) @paginate(defaultCount: 10)',
        ],
        'create' => [
            'query' => '@guard @permission(require: ["user.create"]) @create',
        ],
        'update' => [
            'query' => '@guard @permission(require: ["user.update"]) @update',
        ],
        'delete' => [
            'query' => '@guard @permission(require: ["user.delete"]) @delete',
        ],
    ],
    type_override: [
        'is_admin' => 'Boolean',
        'is_active' => 'Boolean',
        'name' => 'String!',
        'email' => 'String!',
        'cart' => 'Cart @hasOne',
        'cartItems' => '[CartItem!] @hasMany',
        'orders' => '[Order!] @hasMany',
        'products' => '[Product!] @hasMany',
        'role_list' => '[Role!]',
        'permission_list' => '[String!]',
        'is_staff' => 'Boolean',
        'is_seller' => 'Boolean',
        'is_email_verified' => 'Boolean',
    ],
)]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'city',
        'zip',
        'avatar',
        'state',
        'is_admin',
        'provider',
        'provider_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * The verification code columns stay hidden on purpose: LaraQL builds the
     * GraphQL schema from the table, so hiding them is what keeps the hashed
     * code out of the API surface.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'provider_id',
        'verification_code',
        'verification_code_expires_at',
        'verification_attempts',
        'password_reset_code',
        'password_reset_code_expires_at',
        'password_reset_attempts',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verification_code_expires_at' => 'datetime',
            'verification_attempts' => 'integer',
            'password_reset_code_expires_at' => 'datetime',
            'password_reset_attempts' => 'integer',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Admins are either flagged directly (legacy column) or hold the
     * super-admin role. Everything else is decided by permissions.
     */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin || $this->hasRole(RoleSeeder::SUPER_ADMIN);
    }

    /**
     * A seller trades their own products inside the admin panel.
     *
     * Selling is a role, not an administrator flag: a seller passes the coarse
     * `admin` gate (so the panel opens) while every query and mutation narrows
     * the data down to what they own.
     */
    public function isSeller(): bool
    {
        // A super admin already sees everything, so the ownership filter never
        // applies to them even if they also hold the seller role.
        if ($this->isAdmin()) {
            return false;
        }

        return $this->hasRole(RoleSeeder::SELLER);
    }

    /**
     * Anyone allowed into the admin panel: administrators, sellers and staff
     * holding any other role.
     */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->roles()->exists();
    }

    /** Products this user sells. Empty for administrators and customers. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Roles shaped for the GraphQL `Role` type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRoleListAttribute(): array
    {
        return $this->roles->map(fn ($role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'users_count' => 0,
            'is_locked' => false,
        ])->values()->all();
    }

    /**
     * Panel access hints, so the SPA never has to re-derive them from the role
     * list. `is_seller` additionally scopes the admin UI to the seller's own
     * products and orders.
     */
    public function getIsStaffAttribute(): bool
    {
        return $this->isStaff();
    }

    public function getIsSellerAttribute(): bool
    {
        return $this->isSeller();
    }

    public function getIsEmailVerifiedAttribute(): bool
    {
        return $this->hasVerifiedEmail();
    }

    /**
     * Every permission the user effectively holds, so the SPA can gate UI in a
     * single round trip.
     *
     * @return array<int, string>
     */
    public function getPermissionListAttribute(): array
    {
        // Super admins hold everything through Gate::before rather than direct
        // permission rows, so return the full catalogue to keep the SPA's UI
        // gating consistent with what the API will actually allow.
        if ($this->hasRole(RoleSeeder::SUPER_ADMIN) && ! $this->getDirectPermissions()->count()) {
            return \Spatie\Permission\Models\Permission::query()
                ->orderBy('name')
                ->pluck('name')
                ->unique()
                ->values()
                ->all();
        }

        return $this->getAllPermissions()->pluck('name')->unique()->values()->all();
    }

    /**
     * Make sure every user always owns exactly one cart.
     *
     * The relation is refreshed after creation: without that, a user instance
     * that already resolved `cart` to null would keep creating a new cart on
     * every call.
     */
    public function cartOrCreate(): Cart
    {
        $cart = Cart::firstOrCreate(['user_id' => $this->id]);

        $this->setRelation('cart', $cart);

        return $cart;
    }

    /**
     * Remove a replaced / cleared avatar from storage.
     *
     * The profile form stores a single `avatar`, so a new value (or an empty
     * one) always orphans the previous file. Deleting it here means the Oracle
     * Cloud bucket never fills up with pictures nobody references.
     */
    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if (! $user->isDirty('avatar')) {
                return;
            }

            $previous = $user->getRawOriginal('avatar');
            $next = $user->getAttributes()['avatar'] ?? null;

            if ($previous !== null && $previous !== '' && $previous !== $next) {
                app(\App\Services\MediaService::class)->forget($previous);
            }
        });

        static::deleting(function (User $user): void {
            app(\App\Services\MediaService::class)->forget($user->getRawOriginal('avatar'));
        });
    }
}
