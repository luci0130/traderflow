<?php

namespace Turiac\FilamentImageGallery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Turiac\FilamentImageGallery\Database\Factories\GalleryImageRoleFactory;

class GalleryImageRole extends Model
{
    /** @use HasFactory<GalleryImageRoleFactory> */
    use HasFactory;

    protected $fillable = ['gallery_id', 'role'];

    public function getTable(): string
    {
        return (string) config('filament-image-gallery.tables.roles', 'image_gallery_roles');
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(config('filament-image-gallery.models.gallery', Gallery::class));
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(config('filament-image-gallery.models.image', GalleryImage::class), 'image_id');
    }

    protected static function newFactory(): GalleryImageRoleFactory
    {
        return GalleryImageRoleFactory::new();
    }
}
