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
     *   1. `MEDIA_URL` — explicit override (CDN, custom domain)
     *   2. the disk's own configured `url`
     *   3. Oracle Cloud Object Storage's public object URL, when the bucket
     *      lives there (`OCI_NAMESPACE` set)
     *   4. a generic S3 endpoint / virtual-hosted URL
     *   5. the local public disk, APP_URL/storage
     *
     * An empty string means "this disk produces absolute URLs already".
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

        if ($this->driver() === 's3') {
            return $this->objectStoreBaseUrl();
        }

        return rtrim((string) config('app.url'), '/').'/storage';
    }

    /**
     * Public base URL of the configured object store bucket.
     *
     * Oracle Cloud Object Storage is the primary target. Its S3-compatible
     * endpoint is NOT the URL a browser downloads from: a public object lives
     * at
     *
     *   https://objectstorage.<region>.oraclecloud.com/n/<namespace>/b/<bucket>/o
     *
     * (the "native" API), which is why `OCI_PUBLIC_URL` can be given outright
     * and, failing that, is assembled from `OCI_NAMESPACE` / `OCI_REGION`.
     *
     * Any other S3-compatible store (MinIO, DigitalOcean Spaces, plain AWS) is
     * handled by the generic branches below, so switching providers is still a
     * configuration change.
     */
    protected function objectStoreBaseUrl(): string
    {
        $bucket = (string) config('filesystems.disks.s3.bucket');

        if ($bucket === '') {
            return '';
        }

        // 1. Oracle Cloud: an explicit public URL wins.
        $ociPublicUrl = (string) config('media.oci.url');

        if (trim($ociPublicUrl) !== '') {
            return rtrim(trim($ociPublicUrl), '/');
        }

        // 2. Oracle Cloud: assemble it from the namespace and region.
        $namespace = trim((string) config('media.oci.namespace'));

        if ($namespace !== '') {
            $region = trim((string) config('media.oci.region'));

            if ($region === '') {
                // `us-sanjose-1` from the S3 endpoint / disk region.
                $region = (string) config('filesystems.disks.s3.region', 'us-ashburn-1');
            }

            return rtrim(
                "https://objectstorage.{$region}.oraclecloud.com/n/{$namespace}/b/{$bucket}/o",
                '/'
            );
        }

        // 3. A custom endpoint (MinIO, Spaces) is used verbatim.
        $endpoint = config('filesystems.disks.s3.endpoint');

        if (is_string($endpoint) && trim($endpoint) !== '') {
            return rtrim(trim($endpoint), '/').'/'.$bucket;
        }

        // 4. Plain AWS S3, virtual-hosted style.
        $region = (string) config('filesystems.disks.s3.region', 'us-east-1');

        return "https://{$bucket}.s3.{$region}.amazonaws.com";
    }

    /**
     * Whether uploads should be written with a public-read ACL.
     *
     * Oracle Cloud Object Storage's S3 compatibility layer ignores (and on
     * some tenancies rejects) canned ACLs, so it is switched off there and the
     * bucket's own visibility policy is used instead.
     */
    public function usesPublicAcl(): bool
    {
        return (bool) config('media.oci.public_acl', true);
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
        // S3 and most object stores want explicit public visibility, which the
        // local disk ignores. Oracle Cloud ignores (and can reject) canned
        // ACLs, so it relies on the bucket's own visibility policy instead.
        $options = $this->usesPublicAcl() ? ['visibility' => 'public'] : [];

        $this->disk()->putFileAs($folder, $file, $filename, $options);

        return trim($folder, '/').'/'.$filename;
    }

    /** Delete a stored file, ignoring absolute URLs and missing files. */
    public function forget(?string $path): void
    {
        $key = $this->objectKey($path);

        if ($key === null) {
            return;
        }

        if ($this->disk()->exists($key)) {
            $this->disk()->delete($key);
        }
    }

    /**
     * Delete several stored files at once.
     *
     * Used when a record swaps one picture for another (a page hero, an avatar)
     * and when the previous value is about to be overwritten: the old object is
     * removed from the bucket, so Oracle / S3 does not accumulate orphans.
     *
     * @param  array<int, string|null>  $paths
     */
    public function forgetMany(array $paths): void
    {
        foreach ($paths as $path) {
            $this->forget($path);
        }
    }

    /**
     * Is this value a file on our own disk (and therefore ours to delete)?
     *
     * Absolute URLs (external images, legacy rows) and blank values are never
     * touched: deleting them would either fail or remove somebody else's file.
     */
    public function isStoredPath(?string $path): bool
    {
        return $this->objectKey($path) !== null;
    }

    /**
     * Turn whatever was stored into the object key to delete.
     *
     * Accepts the raw path ("products/a.jpg") and the resolved URL
     * ("https://.../n/ns/b/bucket/o/products/a.jpg") so a caller that only has
     * the value it rendered can still clean up the file.
     */
    public function objectKey(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $base = trim($this->baseUrl());

            if ($base === '' || ! str_starts_with($path, $base)) {
                return null;
            }

            $path = ltrim(substr($path, strlen($base)), '/');

            // Oracle's native object URL appends the key after `/o/`, so the
            // substring above is exactly the key — nothing else to strip.
            return $path === '' ? null : rawurldecode($path);
        }

        return ltrim($path, '/');
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
