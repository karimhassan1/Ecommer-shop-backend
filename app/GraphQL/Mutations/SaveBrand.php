<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Brand;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: create or update a brand.
 *
 * One mutation handles both so the panel has a single entry point, mirroring
 * `SaveCategory`. The slug is derived from the name and kept unique, because
 * the storefront can filter by it.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SaveBrand") @guard @permission(require: ["brand.create", "brand.update"])',
    inputs: [
        'id' => 'ID',
        'name' => 'String! @rules(apply:["required","max:120"])',
    ],
    return_type: 'Brand!',
)]
class SaveBrand
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $id = $args['id'] ?? null;

        /** @var Brand|null $brand */
        $brand = $id ? Brand::find($id) : new Brand();

        if (! $brand) {
            throw new UserError('That brand could not be found.');
        }

        // Permission is checked per action, not per field.
        $ability = $brand->exists ? 'brand.update' : 'brand.create';
        $user = $this->requireAuth();

        if (! $user->can($ability)) {
            throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
                'You are not allowed to perform this action.'
            );
        }

        $name = trim((string) $args['name']);

        if ($name === '') {
            throw new UserError('Please enter a brand name.');
        }

        // Two brands with the same label would be indistinguishable in the
        // storefront filter, so they are rejected rather than silently merged.
        $taken = Brand::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($brand->exists, fn ($query) => $query->whereKeyNot($brand->id))
            ->exists();

        if ($taken) {
            throw new UserError('A brand with that name already exists.');
        }

        $brand->name = $name;
        // A renamed brand gets a fresh slug; the model keeps it unique.
        $brand->slug = Str::slug($name) ?: null;
        $brand->save();

        return $brand->fresh();
    }
}
