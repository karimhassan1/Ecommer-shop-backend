<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media / image storage
    |--------------------------------------------------------------------------
    |
    | Everything about where product images live is driven by this block, which
    | in turn reads .env. Swapping local disk for S3 is a configuration change,
    | never a code change:
    |
    |   Local (default)
    |     MEDIA_DISK=public
    |     MEDIA_URL=                      (falls back to APP_URL/storage)
    |
    |   S3 / DigitalOcean Spaces / MinIO
    |     MEDIA_DISK=s3
    |     MEDIA_URL=https://<bucket>.s3.<region>.amazonaws.com
    |     AWS_ACCESS_KEY_ID=...
    |     AWS_SECRET_ACCESS_KEY=...
    |     AWS_DEFAULT_REGION=...
    |     AWS_BUCKET=...
    |
    | The resolved `disk`, `driver` and `url` are published to the SPA through
    | the ShopSettings query so the front end builds identical URLs.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

    /*
     * Optional absolute base URL for the media disk.
     *
     * Leave empty when the disk already knows its own URL (the `public` disk
     * uses APP_URL/storage, the `s3` disk derives it from the bucket). Set it
     * when serving through a CDN or a custom domain in front of the bucket.
     */
    'url' => env('MEDIA_URL'),

    /*
     * Folder the uploads are grouped under inside the disk.
     */
    'folder' => env('MEDIA_FOLDER', 'products'),

    /*
     * Upload limits enforced by the upload endpoint.
     */
    'max_kb' => (int) env('MEDIA_MAX_KB', 5120),

    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
];
