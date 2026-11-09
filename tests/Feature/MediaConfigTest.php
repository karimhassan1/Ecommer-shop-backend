<?php

namespace Tests\Feature;

use App\Models\VariantImage;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Images are stored on whatever disk `MEDIA_DISK` points at and their public URL
 * is built by MediaService, so switching to S3 or a CDN is configuration only.
 *
 * Variant photos are `VariantImage` rows; deleting one also removes the file.
 */
class MediaConfigTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    /** A real 1x1 PNG, independent of the GD extension. */
    protected function png(string $name = 'photo.png'): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $path = tempnam(sys_get_temp_dir(), 'rb').'.png';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media.disk' => 'public',
            'media.url' => null,
            'filesystems.disks.public.url' => null,
            'app.url' => 'http://localhost',
        ]);
    }

    public function test_the_default_disk_is_the_local_public_disk(): void
    {
        $media = app(MediaService::class);

        $this->assertSame('public', $media->diskName());
        $this->assertSame('local', $media->driver());
        $this->assertSame('http://localhost/storage/products/a.jpg', $media->url('products/a.jpg'));
    }

    public function test_absolute_urls_pass_through_unchanged(): void
    {
        $this->assertSame(
            'https://images.unsplash.com/photo.jpg',
            app(MediaService::class)->url('https://images.unsplash.com/photo.jpg')
        );
    }

    public function test_a_custom_media_url_overrides_the_disk_url(): void
    {
        config(['media.url' => 'https://cdn.roserabloom.pk']);

        $this->assertSame(
            'https://cdn.roserabloom.pk/products/a.jpg',
            app(MediaService::class)->url('products/a.jpg')
        );
    }

    public function test_switching_the_disk_changes_the_url(): void
    {
        config([
            'media.disk' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => 'rosera-media',
            'filesystems.disks.s3.region' => 'ap-south-1',
            'filesystems.disks.s3.endpoint' => null,
            'filesystems.disks.s3.url' => null,
        ]);

        $this->assertSame(
            'https://rosera-media.s3.ap-south-1.amazonaws.com/products/a.jpg',
            app(MediaService::class)->url('products/a.jpg')
        );
    }

    public function test_a_custom_endpoint_is_used_for_spaces_or_minio(): void
    {
        config([
            'media.disk' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => 'rosera-media',
            'filesystems.disks.s3.endpoint' => 'https://ams3.digitaloceanspaces.com',
            'filesystems.disks.s3.url' => null,
        ]);

        $this->assertSame(
            'https://ams3.digitaloceanspaces.com/rosera-media/products/a.jpg',
            app(MediaService::class)->url('products/a.jpg')
        );
    }

    public function test_an_upload_is_written_to_the_configured_disk(): void
    {
        Storage::fake('public');

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/uploads', ['file' => $this->png()])
            ->assertCreated()
            ->assertJsonPath('disk', 'public');

        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
    }

    public function test_the_variant_image_url_follows_the_configured_disk(): void
    {
        config(['media.url' => 'https://cdn.example.com']);

        $product = $this->perfumeProduct();
        $variant = $this->defaultVariant($product);

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID) { product(id: $id) { variants { image_urls primary_image } } }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.product.variants.0.image_urls.0', 'https://cdn.example.com/products/test.jpg');

        $this->assertSame('products/test.jpg', $variant->images()->first()->getRawOriginal('url'));
    }

    public function test_deleting_an_image_removes_the_file_from_the_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/remove-me.jpg', 'bytes');

        $product = $this->perfumeProduct();
        $variant = $this->defaultVariant($product);

        $image = VariantImage::create([
            'product_variant_id' => $variant->id,
            'url' => 'products/remove-me.jpg',
            'sort_order' => 1,
            'is_primary' => false,
        ]);

        $image->delete();

        Storage::disk('public')->assertMissing('products/remove-me.jpg');
        $this->assertDatabaseMissing('variant_images', ['id' => $image->id]);
    }

    public function test_shop_settings_publishes_the_media_config_to_the_spa(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            query { ShopSettings { media { disk driver base_url folder max_kb } } }
        ')
            ->assertJsonPath('data.ShopSettings.media.disk', 'public')
            ->assertJsonPath('data.ShopSettings.media.driver', 'local')
            ->assertJsonPath('data.ShopSettings.media.folder', 'products');
    }
}
