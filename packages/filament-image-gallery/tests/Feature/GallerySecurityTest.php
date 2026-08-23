<?php

namespace Turiac\FilamentImageGallery\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Turiac\FilamentImageGallery\Services\GalleryPersister;
use Turiac\FilamentImageGallery\Tests\Fixtures\Product;
use Turiac\FilamentImageGallery\Tests\TestCase;

class GallerySecurityTest extends TestCase
{
    public function test_unsigned_browser_path_is_rejected(): void
    {
        Storage::fake('testing');
        Storage::disk('testing')->put(
            'image-gallery/victim.jpg',
            UploadedFile::fake()->image('victim.jpg')->getContent(),
        );

        $this->expectException(ValidationException::class);

        app(GalleryPersister::class)->persist(
            owner: Product::create(['name' => 'Product']),
            collection: 'images',
            state: [[
                'path' => 'image-gallery/victim.jpg',
                'title' => 'Victim',
                'roles' => ['main'],
            ]],
            configuredRoles: ['main' => 'Main'],
            disk: 'testing',
            visibility: 'public',
            directory: 'image-gallery',
        );
    }

    public function test_soft_delete_preserves_files_and_force_delete_removes_them(): void
    {
        Storage::fake('testing');
        $path = 'legacy/product.jpg';
        Storage::disk('testing')->put($path, UploadedFile::fake()->image('product.jpg')->getContent());
        $product = Product::create(['name' => 'Product']);

        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'images',
            state: [[
                'path' => $path,
                'title' => 'Product',
                'roles' => ['main'],
            ]],
            configuredRoles: ['main' => 'Main'],
            disk: 'testing',
            visibility: 'public',
            directory: 'legacy',
            trustedPaths: [$path],
        );

        $product->delete();

        Storage::disk('testing')->assertExists($path);
        $this->assertDatabaseCount('image_galleries', 1);

        $product->forceDelete();

        Storage::disk('testing')->assertMissing($path);
        $this->assertDatabaseCount('image_galleries', 0);
    }
}
