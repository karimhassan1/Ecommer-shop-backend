<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use CreatesStaffUsers;

    use RefreshDatabase;

    /**
     * A real 1x1 PNG. Using real bytes keeps the test independent of the GD
     * extension, which `UploadedFile::fake()->image()` requires.
     */
    protected function png(string $name = 'kurta.png'): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $path = tempnam(sys_get_temp_dir(), 'rb').'.png';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_an_admin_can_upload_a_product_image(): void
    {
        Storage::fake('public');

        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/uploads', [
            'file' => $this->png('kurta.png'),
            'folder' => 'products',
        ]);

        $response->assertCreated()->assertJsonStructure(['path', 'url', 'name', 'size']);

        $this->assertStringStartsWith('products/', $response->json('path'));

        Storage::disk('public')->assertExists($response->json('path'));
    }

    public function test_guests_cannot_upload(): void
    {
        Storage::fake('public');

        $this->postJson('/api/uploads', [
            'file' => $this->png('x.png'),
        ])->assertUnauthorized();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_regular_customer_cannot_upload(): void
    {
        Storage::fake('public');

        $customer = User::factory()->create(['is_admin' => false]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $this->png('x.png'),
            ])
            ->assertForbidden();
    }

    public function test_only_image_files_are_accepted(): void
    {
        Storage::fake('public');

        $admin = $this->adminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }
}
