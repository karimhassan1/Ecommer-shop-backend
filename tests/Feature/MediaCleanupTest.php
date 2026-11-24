<?php

namespace Tests\Feature;

use App\Models\PageContent;
use App\Models\User;
use App\Models\VariantImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Removing a picture also deletes it from storage.
 *
 * The shop keeps its images on an object store (Oracle Cloud in production), so a
 * row that is deleted or re-pointed without cleaning up the object leaves a file
 * nobody can see but everybody pays for. These tests cover every place a picture
 * can disappear from: a variant photo, a page hero, a product deletion and a
 * freshly uploaded file the admin changed their mind about.
 */
class MediaCleanupTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'media.disk' => 'public',
            'media.url' => null,
            'filesystems.disks.public.url' => null,
            'app.url' => 'http://localhost',
        ]);
    }

    /** Put real bytes on the fake disk and return the stored path. */
    protected function fakeFile(string $path): string
    {
        Storage::disk('public')->put($path, 'fake-image-bytes');

        return $path;
    }

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

    public function test_deleting_a_variant_photo_removes_the_file(): void
    {
        $product = $this->perfumeProduct();
        $variant = $this->defaultVariant($product);

        $path = $this->fakeFile('products/old-photo.jpg');

        $image = VariantImage::create([
            'product_variant_id' => $variant->id,
            'url' => $path,
            'sort_order' => 5,
            'is_primary' => false,
        ]);

        Storage::disk('public')->assertExists($path);

        $image->delete();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_replacing_a_page_hero_picture_removes_the_old_file(): void
    {
        $admin = $this->adminUser();

        $old = $this->fakeFile('pages/old-home.jpg');
        $new = $this->fakeFile('pages/new-home.jpg');

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation { SavePageHero(page: "home", hero_image: "'.$old.'") { hero_image } }
        ')->assertJsonPath('data.SavePageHero.hero_image', $old);

        Storage::disk('public')->assertExists($old);

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation { SavePageHero(page: "home", hero_image: "'.$new.'") { hero_image } }
        ')->assertJsonPath('data.SavePageHero.hero_image', $new);

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_saving_other_copy_keeps_the_current_page_picture(): void
    {
        $admin = $this->adminUser();
        $path = $this->fakeFile('pages/about.jpg');

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation { SavePageHero(page: "about", hero_image: "'.$path.'") { hero_image } }
        ');

        // Saving the same picture again (or only the copy) must keep the file:
        // only a real change deletes it.
        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation { SavePageHero(page: "about", title: "Our Story") { hero_image } }
        ')->assertJsonPath('data.SavePageHero.hero_image', $path);

        Storage::disk('public')->assertExists($path);

        PageContent::where('page', 'about')->firstOrFail()->delete();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_a_product_removes_every_variant_photo(): void
    {
        $admin = $this->adminUser();

        $product = $this->perfumeProduct();
        $variant = $this->defaultVariant($product);

        $path = $this->fakeFile('products/gallery.jpg');

        VariantImage::create([
            'product_variant_id' => $variant->id,
            'url' => $path,
            'sort_order' => 7,
            'is_primary' => false,
        ]);

        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation ($id: ID!) { DeleteProduct(id: $id) { message } }
        ', ['id' => (string) $product->id])
            ->assertJsonPath('data.DeleteProduct.message', 'Product deleted successfully.');

        // The variant rows cascade, so this proves the product hook swept the
        // gallery rather than relying on the per-image event.
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, VariantImage::where('product_variant_id', $variant->id)->count());
    }

    public function test_removing_a_freshly_uploaded_picture_deletes_it(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/uploads', [
            'file' => $this->png('new.png'),
            'folder' => 'products',
        ])->assertCreated();

        $path = $response->json('path');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/uploads', ['path' => $path])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_path_outside_the_media_folder_cannot_be_deleted(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/uploads', ['path' => 'products/../../.env'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/uploads', ['path' => 'other-folder/secret.jpg'])
            ->assertStatus(422);
    }

    public function test_a_regular_customer_cannot_delete_files(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $path = $this->fakeFile('products/mine.jpg');

        $this->actingAs($customer, 'sanctum')
            ->deleteJson('/api/uploads', ['path' => $path])
            ->assertForbidden();

        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_an_avatar_removes_the_old_file(): void
    {
        $user = User::factory()->create();
        $user->cartOrCreate();

        $old = $this->fakeFile('avatars/old.jpg');
        $new = $this->fakeFile('avatars/new.jpg');

        $user->forceFill(['avatar' => $old])->save();

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation { UpdateProfile(avatar: "'.$new.'") { id } }
        ')->assertJsonMissingPath('errors');

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }
}
