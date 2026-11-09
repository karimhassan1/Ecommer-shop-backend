<?php declare(strict_types=1);

namespace App\GraphQL\Types;

use Nodesol\LaraQL\Attributes\Type;

/**
 * Where product images are stored and how to build their public URL.
 *
 * Driven entirely by `.env` (`MEDIA_DISK`, `MEDIA_URL`, AWS_*), so the front end
 * can follow the backend from the local public disk to S3 or a CDN without a
 * code change on either side.
 */
#[Type(
    name: 'MediaConfig',
    columns: [
        'disk' => 'String!',
        'driver' => 'String!',
        'base_url' => 'String!',
        'folder' => 'String!',
        'max_kb' => 'Int!',
    ],
)]
class MediaConfig
{
    public function __construct(
        public string $disk = 'public',
        public string $driver = 'local',
        public string $base_url = '',
        public string $folder = 'products',
        public int $max_kb = 5120,
    ) {}
}
