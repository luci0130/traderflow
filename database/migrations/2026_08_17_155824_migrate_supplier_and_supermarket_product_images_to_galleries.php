<?php

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $claimedPaths = DB::table('image_gallery_images')
            ->pluck('path')
            ->flip()
            ->map(fn (): bool => true)
            ->all();

        $this->migrateImages(
            table: 'supplier_products',
            ownerType: (new SupplierProduct)->getMorphClass(),
            collection: 'supplier-products',
            claimedPaths: $claimedPaths,
        );

        $this->migrateImages(
            table: 'supermarket_products',
            ownerType: (new SupermarketProduct)->getMorphClass(),
            collection: 'supermarket-products',
            claimedPaths: $claimedPaths,
        );
    }

    public function down(): void
    {
        $this->removeGalleries(
            table: 'supplier_products',
            ownerType: (new SupplierProduct)->getMorphClass(),
            collection: 'supplier-products',
        );

        $this->removeGalleries(
            table: 'supermarket_products',
            ownerType: (new SupermarketProduct)->getMorphClass(),
            collection: 'supermarket-products',
        );
    }

    /**
     * @param  array<string, bool>  $claimedPaths
     */
    private function migrateImages(string $table, string $ownerType, string $collection, array &$claimedPaths): void
    {
        $defaultRoles = array_values(array_unique([
            'main',
            ...config('filament-image-gallery.default_roles', []),
        ]));

        DB::table($table)
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($ownerType, $collection, $defaultRoles, &$claimedPaths): void {
                foreach ($products as $product) {
                    $galleryExists = DB::table('image_galleries')
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $product->id)
                        ->where('collection', $collection)
                        ->exists();

                    if ($galleryExists) {
                        continue;
                    }

                    $timestamp = now();
                    $path = $this->unsharedPath((string) $product->image_path, $claimedPaths);
                    $metadata = $this->metadataFor($path);
                    $galleryId = DB::table('image_galleries')->insertGetId([
                        'owner_type' => $ownerType,
                        'owner_id' => $product->id,
                        'collection' => $collection,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                    $imageId = DB::table('image_gallery_images')->insertGetId([
                        'gallery_id' => $galleryId,
                        'uploader_type' => null,
                        'uploader_id' => null,
                        'disk' => 'public',
                        'path' => $path,
                        'visibility' => 'public',
                        'original_filename' => basename((string) $product->image_path),
                        'title' => Str::of(basename((string) $product->image_path))->beforeLast('.')->value(),
                        'alt_text' => null,
                        'mime_type' => $metadata['mime_type'],
                        'size' => $metadata['size'],
                        'original_size' => $metadata['size'],
                        'width' => $metadata['width'],
                        'height' => $metadata['height'],
                        'focal_x' => null,
                        'focal_y' => null,
                        'sort_order' => 1,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    foreach ($defaultRoles as $role) {
                        DB::table('image_gallery_roles')->insert([
                            'gallery_id' => $galleryId,
                            'image_id' => $imageId,
                            'role' => $role,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    }

                    $claimedPaths[$path] = true;
                }
            });
    }

    /**
     * Existing legacy records can point at the same physical file. Gallery files
     * are independently owned, so duplicate references receive their own copy.
     *
     * @param  array<string, bool>  $claimedPaths
     */
    private function unsharedPath(string $path, array $claimedPaths): string
    {
        if (! isset($claimedPaths[$path]) || ! Storage::disk('public')->exists($path)) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $copyPath = trim(dirname($path), '.').'/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');

        return Storage::disk('public')->copy($path, $copyPath) ? $copyPath : $path;
    }

    private function removeGalleries(string $table, string $ownerType, string $collection): void
    {
        $galleries = DB::table('image_galleries')
            ->where('owner_type', $ownerType)
            ->where('collection', $collection)
            ->get();

        foreach ($galleries as $gallery) {
            $legacyPath = DB::table($table)->where('id', $gallery->owner_id)->value('image_path');

            foreach (DB::table('image_gallery_images')->where('gallery_id', $gallery->id)->pluck('path') as $path) {
                if ($path !== $legacyPath) {
                    Storage::disk('public')->delete($path);
                }
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
            'mime_type' => 'image/webp',
            'size' => 0,
            'width' => 1,
            'height' => 1,
        ];
    }
};
