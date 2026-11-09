<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for where images are stored and how their public URL
 * is built.
 *
 * The disk comes from `config('media.disk')` (i.e. `.env` -> `MEDIA_DISK`), so
 * switching from the local public disk to S3 requires no code change. Every
 * consumer — the upload endpoint, the VariantImage accessor, the GraphQL
 * settings payload — goes through this class so URLs can never disagree.
 */
class MediaService
{
    /** The configured disk name, e.g. "public" or "s3". */
    public function diskName(): string
    {
        return (string) config('media.disk', 'public');
    }

    /** The resolved disk instance. */
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /** The driver behind the disk, e.g. "local" or "s3". */
    public function driver(): string
    {
        return (string) config("filesystems.disks.{$this->diskName()}.driver", 'local');
    }

    /** Folder uploads are grouped into. */
    public function folder(string $fallback = 'products'): string
    {
        return (string) (config('media.folder') ?: $fallback);
    }

    /**
     * Base URL that relative image paths resolve against.
     *
     * Priority:
     *   1. `MEDIA_URL` — explicit override (CDN, custom domain, MinIO endpoint)
     *   2. the disk's own configured `url`
     *   3. for the local public disk, APP_URL/storage
     *
     * An empty string means "this disk produces absolute URLs already", which
     * is the case for S3 when `AWS_URL` is set.
     */
    public function baseUrl(): string
    {
        $explicit = config('media.url');

        if (is_string($explicit) && trim($explicit) !== '') {
            return rtrim(trim($explicit), '/');
        }

        $diskUrl = config("filesystems.disks.{$this->diskName()}.url");

        if (is_string($diskUrl) && trim($diskUrl) !== '') {
            return rtrim(trim($diskUrl), '/');
        }

        // An object store produces absolute URLs from its own details, so build
        // the virtual-hosted style URL when only the bucket and region are set.
        if ($this->driver() === 's3') {
            $bucket = (string) config('filesystems.disks.s3.bucket');
            $endpoint = config('filesystems.disks.s3.endpoint');

            if ($bucket === '') {
                return '';
            }

            $region = (string) config('filesystems.disks.s3.region', 'us-east-1');

            // A custom endpoint (MinIO, DigitalOcean Spaces) is used verbatim.
            if (is_string($endpoint) && trim($endpoint) !== '') {
                return rtrim(trim($endpoint), '/').'/'.$bucket;
            }

            return "https://{$bucket}.s3.{$region}.amazonaws.com";
        }

        return rtrim((string) config('app.url'), '/').'/storage';
    }

    /**
     * Absolute URL for a stored path.
     *
     * Absolute URLs (legacy rows, externally hosted images) pass straight
     * through so old data keeps working after the disk is switched.
     */
    public function url(?string $path): string
    {
        if (! $path) {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');
    }

    /**
     * Store an uploaded file and return the path written to the database.
     */
    public function put(string $folder, string $filename, $file): string
    {
        // S3 and most object stores want explicit public visibility; the local
        // disk ignores it.
        $this->disk()->putFileAs($folder, $file, $filename, ['visibility' => 'public']);

        return trim($folder, '/').'/'.$filename;
    }

    /** Delete a stored file, ignoring absolute URLs and missing files. */
    public function forget(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        if ($this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    /**
     * Shape consumed by the SPA so it can build the same URLs locally.
     *
     * @return array{disk: string, driver: string, base_url: string, folder: string, max_kb: int}
     */
    public function clientConfig(): array
    {
        return [
            'disk' => $this->diskName(),
            'driver' => $this->driver(),
            'base_url' => $this->baseUrl(),
            'folder' => $this->folder(),
            'max_kb' => (int) config('media.max_kb', 5120),
        ];
    }
}
