<?php

/*
|--------------------------------------------------------------------------
| LaraQL
|--------------------------------------------------------------------------
|
| Mirrors the package defaults so they are visible and overridable. The
| generated GraphQL schema is cached outside `local`, which means any change to
| a model attribute or a `@rules` string requires clearing the cache:
|
|     php artisan lighthouse:clear-cache
|
| Leave `LARAQL_CACHE=true` in production, but clear it on every deploy.
|
*/

return [
    'directories' => [
        app_path('Models'),
        app_path('GraphQL'),
    ],

    'models' => [
        'auto_include' => (bool) env('LARAQL_MODELS_AUTO_INCLUDE', false),
    ],

    'cache' => (bool) env('LARAQL_CACHE', ! config('app.debug')),

    'cache_store' => env('LARAQL_CACHE_STORE'),
];
