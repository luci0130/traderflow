<?php

use App\Modules\Products\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $hasLegacyGallery = Schema::hasTable('product_images');
        $ownerType = (new Product)->getMorphClass();
        $defaultRoles = config('filament-image-gallery.default_roles', ['main']);

        DB::table('products')->orderBy('id')->chunkById(100, function ($products) use ($hasLegacyGallery, $ownerType, $defaultRoles): void {
            foreach ($products as $product) {
                $legacyImages = $hasLegacyGallery
                    ? DB::table('product_images')->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get()
                    : collect();

                if (filled($product->image_path) && ! $legacyImages->contains('path', $product->image_path)) {
                    $legacyImages->prepend((object) [
                        'path' => $product->image_path,
                        'is_primary' => true,
                        'sort_order' => 0,
                    ]);
                }

                if ($legacyImages->isEmpty()) {
                    continue;
                }

                $timestamp = now();
                $galleryId = DB::table('image_galleries')->insertGetId([
                    'owner_type' => $ownerType,
                    'owner_id' => $product->id,
                    'collection' => 'products',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                foreach ($legacyImages->values() as $index => $legacyImage) {
                    $metadata = $this->metadataFor($legacyImage->path);
                    $imageId = DB::table('image_gallery_images')->insertGetId([
                        'gallery_id' => $galleryId,
                        'uploader_type' => null,
                        'uploader_id' => null,
                        'disk' => 'public',
                        'path' => $legacyImage->path,
                        'visibility' => 'public',
                        'original_filename' => basename($legacyImage->path),
                        'title' => Str::of(basename($legacyImage->path))->beforeLast('.')->value(),
                        'alt_text' => null,
                        'mime_type' => $metadata['mime_type'],
                        'size' => $metadata['size'],
                        'original_size' => $metadata['size'],
                        'width' => $metadata['width'],
                        'height' => $metadata['height'],
                        'focal_x' => null,
                        'focal_y' => null,
                        'sort_order' => $index + 1,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    if ($index === 0) {
                        foreach ($defaultRoles as $role) {
                            DB::table('image_gallery_roles')->insert([
                                'gallery_id' => $galleryId,
                                'image_id' => $imageId,
                                'role' => $role,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]);
                        }
                    }
                }
            }
        });

        Schema::dropIfExists('product_images');
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->boolean('is_primary')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['product_id', 'path']);
                $table->index(['product_id', 'is_primary']);
                $table->index(['product_id', 'sort_order']);
            });
        }

        $ownerType = (new Product)->getMorphClass();
        $galleries = DB::table('image_galleries')
            ->where('owner_type', $ownerType)
            ->where('collection', 'products')
            ->get();

        foreach ($galleries as $gallery) {
            $mainImageId = DB::table('image_gallery_roles')
                ->where('gallery_id', $gallery->id)
                ->where('role', 'main')
                ->value('image_id');

            foreach (DB::table('image_gallery_images')->where('gallery_id', $gallery->id)->get() as $image) {
                DB::table('product_images')->insert([
                    'product_id' => $gallery->owner_id,
                    'path' => $image->path,
                    'is_primary' => $image->id === $mainImageId,
                    'sort_order' => $image->sort_order,
                    'created_at' => $image->created_at,
                    'updated_at' => $image->updated_at,
                ]);
            }

            DB::table('image_galleries')->where('id', $gallery->id)->delete();
        }
    }

    /**
     * @return array{mime_type: string, size: int, width: int, height: int}
     */
    private function metadataFor(string $path): array
    {
        try {
            if (Storage::disk('public')->exists($path)) {
                $contents = Storage::disk('public')->get($path);
                $dimensions = @getimagesizefromstring($contents);

                if ($dimensions !== false) {
                    return [
                        'mime_type' => $dimensions['mime'] ?? 'image/webp',
                        'size' => (int) Storage::disk('public')->size($path),
                        'width' => (int) $dimensions[0],
                        'height' => (int) $dimensions[1],
                    ];
                }
            }
        } catch (Throwable) {
        }

        return [
            'mime_type' => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                default => 'image/webp',
            },
            'size' => 0,
            'width' => 0,
            'height' => 0,
        ];
    }
};
