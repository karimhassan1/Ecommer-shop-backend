<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Product access.
 *
 * Two axes are checked:
 *
 *   1. the ability  — `product.view` / `.create` / `.update` / `.delete`
 *   2. the row      — a seller may only touch products they own
 *
 * Administrators and other staff pass the row check for every product; the
 * seller restriction is applied only when `isSeller()` is true, so editing a
 * product owned by the shop stays possible for staff.
 */
class ProductPolicy extends PermissionPolicy
{
    protected string $resource = 'product';

    /**
     * A super admin passes everything, but a *seller* never gets the blanket
     * pass: their access depends on the row. The legacy `is_admin` flag keeps
     * its old meaning, so existing accounts are unaffected.
     */
    public function before(User $user): ?bool
    {
        if ($user->hasRole(\Database\Seeders\RoleSeeder::SUPER_ADMIN) || (bool) $user->is_admin) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, ?Product $product = null): bool
    {
        return $this->ownsRow($user, $product) && $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, ?Product $product = null): bool
    {
        return $this->ownsRow($user, $product) && $this->allows($user, 'update');
    }

    public function delete(User $user, ?Product $product = null): bool
    {
        return $this->ownsRow($user, $product) && $this->allows($user, 'delete');
    }

    /**
     * Sellers are confined to their own products. Everyone else (staff,
     * managers, administrators) is unrestricted here.
     */
    protected function ownsRow(User $user, ?Product $product): bool
    {
        if (! $product) {
            return true;
        }

        if (! $user->isSeller()) {
            return true;
        }

        return $product->isOwnedBy($user);
    }
}
