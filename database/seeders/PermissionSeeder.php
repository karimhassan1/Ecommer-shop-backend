<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The full permission catalogue.
 *
 * Every model gets the four standard abilities (`view`, `create`, `update`,
 * `delete`) plus, where it makes sense, a couple of task specific extras.
 * Naming is `resource.action` so the SPA can gate a button with a single string.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Resource => extra abilities beyond the four standard ones.
     *
     * @var array<string, array<int, string>>
     */
    public const RESOURCES = [
        'dashboard' => ['view'],
        'product' => [],
        'brand' => [],
        'category' => [],
        'order' => ['update-status', 'view-all'],
        'review' => ['approve'],
        'message' => ['reply'],
        'user' => ['assign-role'],
        'role' => [],
        'setting' => ['update'],
    ];

    /** The four abilities every resource gets by default. */
    public const STANDARD = ['view', 'create', 'update', 'delete'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The SPA authenticates through Sanctum, while Laravel's own guards use
        // `web`. Creating both keeps `can()` working whichever guard resolved
        // the request.
        foreach (['web', 'sanctum'] as $guard) {
            foreach (self::names() as $name) {
                Permission::findOrCreate($name, $guard);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Every permission name, in a stable order.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::RESOURCES as $resource => $extras) {
            // `dashboard` and `setting` are action-style, not CRUD.
            $abilities = match ($resource) {
                'dashboard' => ['view'],
                'setting' => ['view', 'update'],
                default => array_merge(self::STANDARD, $extras),
            };

            foreach (array_unique($abilities) as $ability) {
                $names[] = "{$resource}.{$ability}";
            }
        }

        return array_values(array_unique($names));
    }
}
