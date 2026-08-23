<?php

namespace Turiac\FilamentImageGallery\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Turiac\FilamentImageGallery\Models\Gallery;
use Turiac\FilamentImageGallery\Models\GalleryImage;

trait HasImageGalleries
{
    public static function bootHasImageGalleries(): void
    {
        static::deleted(function (Model $owner): void {
            if (method_exists($owner, 'isForceDeleting') && ! $owner->isForceDeleting()) {
                return;
            }

            $files = $owner->imageGalleries()
                ->with('images:id,gallery_id,disk,path')
                ->get()
                ->flatMap(fn (Gallery $gallery) => $gallery->images)
                ->map(fn (GalleryImage $image): array => [$image->disk, $image->path])
                ->all();

            $owner->imageGalleries()->delete();

            DB::afterCommit(function () use ($files): void {
                foreach ($files as [$disk, $path]) {
                    try {
                        Storage::disk($disk)->delete($path);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
        });
    }

    public function imageGalleries(): MorphMany
    {
        return $this->morphMany(config('filament-image-gallery.models.gallery', Gallery::class), 'owner');
    }

    public function imageGallery(string $collection = 'default'): ?Gallery
    {
        if ($this->relationLoaded('imageGalleries')) {
            return $this->imageGalleries->firstWhere('collection', $collection);
        }

        return $this->imageGalleries()->where('collection', $collection)->first();
    }

    public function imageForRole(string $role, string $collection = 'default'): ?GalleryImage
    {
        $gallery = $this->imageGallery($collection);

        if ($gallery === null) {
            return null;
        }

        if ($gallery->relationLoaded('images')) {
            if ($gallery->images->contains(fn (GalleryImage $image): bool => ! $image->relationLoaded('roles'))) {
                return $gallery->images()
                    ->whereHas('roles', fn ($query) => $query->where('role', $role))
                    ->first();
            }

            return $gallery->images->first(function (GalleryImage $image) use ($role): bool {
                return $image->roles->contains('role', $role);
            });
        }

        return $gallery->images()
            ->whereHas('roles', fn ($query) => $query->where('role', $role))
            ->first();
    }

    public function scopeWithImageGalleries(Builder $query, string ...$collections): Builder
    {
        return $query->with([
            'imageGalleries' => fn ($galleryQuery) => $galleryQuery
                ->when(
                    $collections !== [],
                    fn ($collectionQuery) => $collectionQuery->whereIn('collection', $collections),
                )
                ->with('images.roles'),
        ]);
    }
}
