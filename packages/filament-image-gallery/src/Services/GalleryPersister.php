<?php

namespace Turiac\FilamentImageGallery\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Turiac\FilamentImageGallery\Contracts\GalleryRepository;
use Turiac\FilamentImageGallery\Models\Gallery;
use Turiac\FilamentImageGallery\Models\GalleryImage;
use Turiac\FilamentImageGallery\Models\GalleryImageRole;
use Turiac\FilamentImageGallery\Support\PendingUploadSigner;

class GalleryPersister implements GalleryRepository
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function stateFor(Model $owner, string $collection): array
    {
        $gallery = $this->galleryQuery()
            ->whereMorphedTo('owner', $owner)
            ->where('collection', $collection)
            ->with(['images.roles', 'images.uploader'])
            ->first();

        if ($gallery === null) {
            return [];
        }

        return $gallery->images->mapWithKeys(fn (GalleryImage $image): array => [
            "record-{$image->getKey()}" => [
                '_id' => $image->getKey(),
                'path' => $image->path,
                'original_filename' => $image->original_filename,
                'title' => $image->title,
                'alt_text' => $image->alt_text,
                'roles' => $image->roles->pluck('role')->all(),
                'mime_type' => $image->mime_type,
                'size' => $image->size,
                'original_size' => $image->original_size,
                'width' => $image->width,
                'height' => $image->height,
                'focal_x' => $image->focal_x,
                'focal_y' => $image->focal_y,
                'disk' => $image->disk,
                'visibility' => $image->visibility,
                'uploaded_at' => $image->created_at?->toIso8601String(),
                'uploader' => $image->uploader?->getAttribute('name'),
            ],
        ])->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $state
     * @param  array<string, string>  $configuredRoles
     */
    public function persist(
        Model $owner,
        string $collection,
        array $state,
        array $configuredRoles,
        string $disk,
        string $visibility,
        string $directory = '',
        string $statePath = 'images',
        array $trustedPaths = [],
    ): void {
        $normalizedState = [];

        foreach ($state as $item) {
            if (! is_array($item)) {
                $this->fail($statePath, __('filament-image-gallery::gallery.validation.invalid_state'));
            }

            if ($item['_is_uploader'] ?? false) {
                continue;
            }

            if (blank($this->pathFrom($item['path'] ?? null))) {
                $this->fail($statePath, __('filament-image-gallery::gallery.validation.invalid_path'));
            }

            $normalizedState[] = $item;
        }

        $state = $normalizedState;
        $this->validateRoles($state, $configuredRoles, $statePath);
        $filesToDelete = [];

        DB::transaction(function () use ($owner, $collection, $state, $disk, $visibility, $directory, $statePath, $trustedPaths, &$filesToDelete): void {
            $gallery = $this->galleryQuery()
                ->whereMorphedTo('owner', $owner)
                ->where('collection', $collection)
                ->lockForUpdate()
                ->first();

            if ($state === []) {
                if ($gallery !== null) {
                    $filesToDelete = $gallery->images()
                        ->get(['disk', 'path'])
                        ->map(fn (GalleryImage $image): array => [$image->disk, $image->path])
                        ->all();
                    $gallery->delete();
                }

                return;
            }

            $gallery ??= $owner->imageGalleries()->firstOrCreate(['collection' => $collection]);
            $gallery = $this->galleryQuery()->lockForUpdate()->findOrFail($gallery->getKey());
            $existingImages = $gallery->images()->lockForUpdate()->get()->keyBy('id');
            $retainedImageIds = [];
            $savedImages = [];
            $orderRows = [];

            foreach ($state as $index => $item) {
                $imageId = filled($item['_id'] ?? null) ? (int) $item['_id'] : null;
                $image = $imageId !== null ? $existingImages->get($imageId) : null;

                if ($imageId !== null && $image === null) {
                    $this->fail($statePath, __('filament-image-gallery::gallery.validation.unknown_image'));
                }

                $path = $this->pathFrom($item['path'] ?? null);
                $itemDisk = ($image !== null && $image->path === $path) ? $image->disk : $disk;
                $itemVisibility = ($image !== null && $image->path === $path) ? $image->visibility : $visibility;

                $this->validatePath(
                    item: $item,
                    path: $path,
                    disk: $itemDisk,
                    directory: $directory,
                    existingImage: $image,
                    statePath: $statePath,
                    trustedPaths: $trustedPaths,
                );

                if ($image !== null && $image->path !== $path) {
                    $filesToDelete[] = [$image->disk, $image->path];
                }

                $metadata = $this->metadataFor($item, $path, $itemDisk, $image, $statePath);
                $title = filled($item['title'] ?? null)
                    ? Str::limit((string) $item['title'], 255, '')
                    : Str::of($metadata['original_filename'])->beforeLast('.')->value();
                $values = [
                    ...$metadata,
                    'disk' => $itemDisk,
                    'path' => $path,
                    'visibility' => $itemVisibility,
                    'title' => $title,
                    'alt_text' => filled($item['alt_text'] ?? null) ? Str::limit((string) $item['alt_text'], 1000, '') : $title,
                    'focal_x' => null,
                    'focal_y' => null,
                    'sort_order' => $index + 1,
                ];

                if ($image === null) {
                    $imageClass = config('filament-image-gallery.models.image', GalleryImage::class);
                    $image = new $imageClass($values);
                    $image->gallery()->associate($gallery);

                    if (($uploader = Auth::user()) instanceof Model) {
                        $image->uploader()->associate($uploader);
                    }

                    $image->save();
                } else {
                    $sortOrder = $values['sort_order'];
                    unset($values['sort_order']);
                    $image->fill($values);

                    if ($image->isDirty()) {
                        $image->save();
                    }

                    if ($image->sort_order !== $sortOrder) {
                        $orderRows[] = [
                            ...$image->getAttributes(),
                            'sort_order' => $sortOrder,
                            'updated_at' => now(),
                        ];
                        $image->sort_order = $sortOrder;
                    }
                }

                $retainedImageIds[] = $image->getKey();
                $savedImages[] = [$image, Arr::wrap($item['roles'] ?? [])];
            }

            $removedImages = $existingImages->except($retainedImageIds);

            if ($orderRows !== []) {
                $this->imageQuery()->upsert($orderRows, ['id'], ['sort_order', 'updated_at']);
            }

            $filesToDelete = [
                ...$filesToDelete,
                ...$removedImages->map(fn (GalleryImage $image): array => [$image->disk, $image->path])->all(),
            ];
            $timestamp = now();
            $roleRows = collect($savedImages)
                ->flatMap(fn (array $savedImage): array => collect($savedImage[1])
                    ->map(fn (string $role): array => [
                        'gallery_id' => $gallery->getKey(),
                        'image_id' => $savedImage[0]->getKey(),
                        'role' => $role,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])
                    ->all())
                ->values();

            $gallery->roles()
                ->whereNotIn('role', $roleRows->pluck('role')->all())
                ->delete();

            if ($roleRows->isNotEmpty()) {
                $roleClass = config('filament-image-gallery.models.role', GalleryImageRole::class);
                $roleClass::query()->upsert(
                    $roleRows->all(),
                    ['gallery_id', 'role'],
                    ['image_id', 'updated_at'],
                );
            }

            $gallery->images()->whereKey($removedImages->modelKeys())->delete();
        });

        $filesToDelete = collect($filesToDelete)
            ->unique(fn (array $file): string => implode(':', $file))
            ->values()
            ->all();

        DB::afterCommit(function () use ($filesToDelete): void {
            foreach ($filesToDelete as [$fileDisk, $path]) {
                try {
                    Storage::disk($fileDisk)->delete($path);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $state
     * @param  array<string, string>  $configuredRoles
     */
    private function validateRoles(array $state, array $configuredRoles, string $statePath): void
    {
        $assignedRoles = [];

        foreach ($state as $item) {
            foreach (Arr::wrap($item['roles'] ?? []) as $role) {
                if (! is_string($role) || ! array_key_exists($role, $configuredRoles)) {
                    $this->fail($statePath, __('filament-image-gallery::gallery.validation.unknown_role', [
                        'role' => is_scalar($role) ? (string) $role : '?',
                    ]));
                }

                if (isset($assignedRoles[$role])) {
                    $this->fail($statePath, __('filament-image-gallery::gallery.validation.duplicate_role', ['role' => $role]));
                }

                $assignedRoles[$role] = true;
            }
        }

        if ($state !== [] && ! isset($assignedRoles['main'])) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.main_required'));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $trustedPaths
     */
    private function validatePath(
        array $item,
        string $path,
        string $disk,
        string $directory,
        ?GalleryImage $existingImage,
        string $statePath,
        array $trustedPaths,
    ): void {
        if ($existingImage !== null && $existingImage->path === $path && $existingImage->disk === $disk) {
            return;
        }

        $isTrustedImport = $directory === '' || in_array($path, $trustedPaths, true);
        $isSignedUpload = app(PendingUploadSigner::class)->isValid(
            is_string($item['_upload_token'] ?? null) ? $item['_upload_token'] : null,
            $disk,
            $path,
        );

        if (! $isTrustedImport && ! $isSignedUpload) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.invalid_path'));
        }

        $directory = trim($directory, '/');

        if (! $isTrustedImport && ($directory === '' || ! str_starts_with($path, $directory.'/'))) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.invalid_path'));
        }

        $claimedPath = $this->imageQuery()
            ->where('disk', $disk)
            ->where('path', $path)
            ->when($existingImage !== null, fn ($query) => $query->whereKeyNot($existingImage->getKey()))
            ->exists();

        if ($claimedPath) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.path_in_use'));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{original_filename: string, mime_type: string, size: int, original_size: int, width: int, height: int}
     */
    private function metadataFor(array $item, string $path, string $disk, ?GalleryImage $existingImage, string $statePath): array
    {
        if ($existingImage !== null && $existingImage->path === $path) {
            return [
                'original_filename' => $existingImage->original_filename,
                'mime_type' => $existingImage->mime_type,
                'size' => $existingImage->size,
                'original_size' => $existingImage->original_size,
                'width' => $existingImage->width,
                'height' => $existingImage->height,
            ];
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.invalid_path'));
        }

        $size = (int) $storage->size($path);

        if ($size < 1 || $size > ((int) config('filament-image-gallery.max_size', 10240) * 1024)) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.max_size', [
                'size' => config('filament-image-gallery.max_size', 10240) / 1024,
            ]));
        }
        $temporaryPath = tempnam(sys_get_temp_dir(), 'fig-meta-');
        $source = $storage->readStream($path);
        $target = $temporaryPath === false ? false : fopen($temporaryPath, 'wb');

        if ($temporaryPath === false || $source === false || $target === false) {
            is_resource($source) && fclose($source);
            is_resource($target) && fclose($target);
            $temporaryPath !== false && @unlink($temporaryPath);

            $this->fail($statePath, __('filament-image-gallery::gallery.validation.invalid_image'));
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        $dimensions = @getimagesize($temporaryPath);
        @unlink($temporaryPath);

        if ($dimensions === false) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.invalid_image'));
        }

        if (
            (int) $dimensions[0] > (int) config('filament-image-gallery.max_width', 5000)
            || (int) $dimensions[1] > (int) config('filament-image-gallery.max_height', 5000)
        ) {
            $this->fail($statePath, __('filament-image-gallery::gallery.validation.dimensions', [
                'width' => config('filament-image-gallery.max_width', 5000),
                'height' => config('filament-image-gallery.max_height', 5000),
            ]));
        }

        return [
            'original_filename' => Str::limit((string) ($item['original_filename'] ?? basename($path)), 255, ''),
            'mime_type' => (string) ($dimensions['mime'] ?? $storage->mimeType($path)),
            'size' => $size,
            'original_size' => max($size, min(
                (int) ($item['original_size'] ?? $size),
                (int) config('filament-image-gallery.max_size', 10240) * 1024,
            )),
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
        ];
    }

    private function pathFrom(mixed $state): string
    {
        if (is_string($state)) {
            return $state;
        }

        return (string) collect(Arr::wrap($state))->first(fn ($path): bool => is_string($path));
    }

    private function fail(string $statePath, string $message): never
    {
        throw ValidationException::withMessages([$statePath => $message]);
    }

    private function galleryQuery(): Builder
    {
        $galleryClass = config('filament-image-gallery.models.gallery', Gallery::class);

        return $galleryClass::query();
    }

    private function imageQuery(): Builder
    {
        $imageClass = config('filament-image-gallery.models.image', GalleryImage::class);

        return $imageClass::query();
    }
}
