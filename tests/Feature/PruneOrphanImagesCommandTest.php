<?php

namespace Tests\Feature;

use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Turiac\FilamentImageGallery\Services\GalleryPersister;

class PruneOrphanImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Files are only considered once they are old enough, so back-date the ones
     * the sweep is meant to look at.
     */
    private function putAgedFile(string $path, string $contents = 'x'): void
    {
        Storage::disk('public')->put($path, $contents);
        touch(Storage::disk('public')->path($path), now()->subWeek()->getTimestamp());
    }

    public function test_it_deletes_image_files_no_record_points_at(): void
    {
        $this->putAgedFile('supermarket-products/orphan.jpg');
        $this->putAgedFile('product-categories/orphan.png');

        $this->artisan('images:prune-orphans')->assertSuccessful();

        Storage::disk('public')->assertMissing('supermarket-products/orphan.jpg');
        Storage::disk('public')->assertMissing('product-categories/orphan.png');
    }

    public function test_it_keeps_files_a_record_still_references(): void
    {
        $this->putAgedFile('supermarket-products/kept.jpg');
        $this->putAgedFile('supermarket-photos/pending.jpg');

        $product = SupermarketProduct::factory()->create(['image_path' => 'supermarket-products/kept.jpg']);
        SupermarketPricePhoto::factory()->create([
            'path' => 'supermarket-photos/pending.jpg',
            'status' => SupermarketPricePhoto::STATUS_PENDING,
        ]);

        Storage::disk('public')->put(
            'products/shared/gallery-kept.jpg',
            UploadedFile::fake()->image('gallery-kept.jpg', 40, 40)->getContent(),
        );

        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'supermarket-products',
            state: [[
                'path' => 'products/shared/gallery-kept.jpg',
                'original_filename' => 'gallery-kept.jpg',
                'title' => 'gallery-kept',
                'alt_text' => null,
                'roles' => ['main'],
            ]],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $galleryPath = $product->imageGallery('supermarket-products')->images->first()->path;

        // Guard the assertion below: the gallery file must sit in a directory
        // the command actually sweeps.
        $this->assertTrue(Str::startsWith($galleryPath, ['products/', 'image-gallery/']), $galleryPath);

        touch(Storage::disk('public')->path($galleryPath), now()->subWeek()->getTimestamp());

        $this->artisan('images:prune-orphans')->assertSuccessful();

        Storage::disk('public')->assertExists('supermarket-products/kept.jpg');
        Storage::disk('public')->assertExists('supermarket-photos/pending.jpg');
        Storage::disk('public')->assertExists($galleryPath);
    }

    public function test_it_purges_the_files_of_already_reviewed_price_photos(): void
    {
        $this->putAgedFile('supermarket-photos/reviewed.jpg');

        $photo = SupermarketPricePhoto::factory()->create([
            'path' => 'supermarket-photos/reviewed.jpg',
            'status' => SupermarketPricePhoto::STATUS_DONE,
        ]);

        $this->artisan('images:prune-orphans')->assertSuccessful();

        Storage::disk('public')->assertMissing('supermarket-photos/reviewed.jpg');
        $this->assertNull($photo->fresh()->path);
        $this->assertDatabaseHas('supermarket_price_photos', ['id' => $photo->id]);
    }

    public function test_it_leaves_recently_uploaded_files_alone(): void
    {
        Storage::disk('public')->put('supplier-products/just-uploaded.jpg', 'x');

        $this->artisan('images:prune-orphans')->assertSuccessful();

        Storage::disk('public')->assertExists('supplier-products/just-uploaded.jpg');
    }

    public function test_it_never_touches_directories_outside_the_image_folders(): void
    {
        $this->putAgedFile('documents/1/contract.pdf');

        $this->artisan('images:prune-orphans')->assertSuccessful();

        Storage::disk('public')->assertExists('documents/1/contract.pdf');
    }

    public function test_the_command_is_scheduled_every_ten_days(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'images:prune-orphans'));

        $this->assertCount(1, $events);
        $this->assertSame('0 3 */10 * *', $events->first()->expression);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $this->putAgedFile('supermarket-products/orphan.jpg');

        $photo = SupermarketPricePhoto::factory()->create([
            'path' => 'supermarket-photos/reviewed.jpg',
            'status' => SupermarketPricePhoto::STATUS_DONE,
        ]);

        $this->artisan('images:prune-orphans', ['--dry-run' => true])
            ->expectsOutputToContain('supermarket-products/orphan.jpg')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('supermarket-products/orphan.jpg');
        $this->assertSame('supermarket-photos/reviewed.jpg', $photo->fresh()->path);
    }
}
