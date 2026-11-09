<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Category;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;
use Nodesol\LaraQL\Attributes\Mutation;

/**
 * Admin only: create or update a category.
 *
 * One mutation handles both so the admin form has a single entry point. The
 * slug is derived from the name and kept unique across the catalogue, because
 * the storefront filters by it (`/shop?category=<slug>`).
 */
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SaveCategory") @guard @permission(require: ["category.create", "category.update"])',
    inputs: [
        'id' => 'ID',
        'name' => 'String! @rules(apply:["required","max:120"])',
        'type' => 'String! @rules(apply:["required","in:clothing,perfume"])',
        'sort_order' => 'Int! = 0 @rules(apply:["integer","min:0"])',
    ],
    return_type: 'Category!',
)]
class SaveCategory
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $id = $args['id'] ?? null;

        /** @var Category|null $category */
        $category = $id ? Category::find($id) : new Category();

        if (! $category) {
            throw new UserError('That category could not be found.');
        }

        $isNew = ! $category->exists;

        // Permission is checked per action, not per field.
        $ability = $isNew ? 'category.create' : 'category.update';
        $user = $this->requireAuth();

        if (! $user->can($ability)) {
            throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
                'You are not allowed to perform this action.'
            );
        }

        $name = trim((string) $args['name']);
        $slug = Str::slug($name);

        if ($slug === '') {
            throw new UserError('Please use a name that contains letters or numbers.');
        }

        // Slugs are the storefront's filter key, so they must be unique.
        $taken = Category::query()
            ->where('slug', $slug)
            ->when($category->exists, fn ($query) => $query->whereKeyNot($category->id))
            ->exists();

        if ($taken) {
            throw new UserError('A category with a similar name already exists.');
        }

        $category->fill([
            'name' => $name,
            'slug' => $slug,
            'type' => (string) $args['type'],
            'sort_order' => (int) ($args['sort_order'] ?? 0),
        ])->save();

        return $category->fresh();
    }
}
