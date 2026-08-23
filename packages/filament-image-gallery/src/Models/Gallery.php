<?php

namespace Turiac\FilamentImageGallery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Turiac\FilamentImageGallery\Database\Factories\GalleryFactory;

class Gallery extends Model
{
    /** @use HasFactory<GalleryFactory> */
    use HasFactory;

    protected $fillable = ['collection'];

    public function getTable(): string
    {
        return (string) config('filament-image-gallery.tables.galleries', 'image_galleries');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function images(): HasMany
    {
        return $this->hasMany(config('filament-image-gallery.models.image', GalleryImage::class))
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(config('filament-image-gallery.models.role', GalleryImageRole::class));
    }

    protected static function newFactory(): GalleryFactory
    {
        return GalleryFactory::new();
    }
}
