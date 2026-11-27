<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\FabricType;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: create or update a fabric type.
 *
 * One mutation handles both so the panel has a single entry point, mirroring
 * `SaveBrand` / `SaveCategory`. The slug is derived from the name and kept
 * unique, because it is what the storefront filter links to.
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SaveFabricType") @guard @permission(require: ["product.create", "product.update"])',
    inputs: [
        'id' => 'ID',
        'name' => 'String! @rules(apply:["required","max:80"])',
        'sort_order' => 'Int! = 0',
        'is_active' => 'Boolean! = true',
    ],
    return_type: 'FabricType!',
)]
class SaveFabricType
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $id = $args['id'] ?? null;

        /** @var FabricType|null $fabric */
        $fabric = $id ? FabricType::find($id) : new FabricType();

        if (! $fabric) {
            throw new UserError('That fabric type could not be found.');
        }

        // Permission is checked per action, not per field.
        $ability = $fabric->exists ? 'product.update' : 'product.create';
        $user = $this->requireAuth();

        if (! $user->can($ability)) {
            throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
                'You are not allowed to perform this action.'
            );
        }

        $name = trim((string) $args['name']);

        if ($name === '') {
            throw new UserError('Please enter a fabric type name.');
        }

        // Two entries with the same label would be indistinguishable in the
        // storefront filter, so they are rejected rather than silently merged.
        $taken = FabricType::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($fabric->exists, fn ($query) => $query->whereKeyNot($fabric->id))
            ->exists();

        if ($taken) {
            throw new UserError('A fabric type with that name already exists.');
        }

        $fabric->name = $name;
        $fabric->sort_order = (int) ($args['sort_order'] ?? 0);
        $fabric->is_active = (bool) ($args['is_active'] ?? true);

        // A renamed fabric gets a fresh slug; the model keeps it unique.
        if (! $fabric->exists || $fabric->isDirty('name')) {
            $fabric->slug = Str::slug($name) ?: null;
        }

        $fabric->save();

        return $fabric->fresh();
    }
}
