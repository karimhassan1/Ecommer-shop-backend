<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pakistan provinces and cities (third party geo data)
    |--------------------------------------------------------------------------
    |
    | The checkout offers every Pakistani province with its cities. The list is
    | pulled from a public country/state/city API and merged over the shipped
    | `config/pakistan.php`, which stays as the offline fallback.
    |
    | The default provider needs no key. Point `base_url` at a different
    | country/state/city provider (or your own proxy) if this one is retired;
    | set `GEO_ENABLED=false` to skip the network call entirely.
    |
    */

    'geo' => [
        'enabled' => env('GEO_ENABLED', true),
        'base_url' => env('GEO_BASE_URL', 'https://countriesnow.space/api/v0.1'),
        'country' => env('GEO_COUNTRY', 'Pakistan'),
        'timeout' => (int) env('GEO_TIMEOUT', 8),
        // A day: provinces do not change often, and the selects read this on
        // every checkout render.
        'cache_ttl' => (int) env('GEO_CACHE_TTL', 86400),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
