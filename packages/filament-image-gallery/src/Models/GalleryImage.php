<?php

namespace Turiac\FilamentImageGallery\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Turiac\FilamentImageGallery\Database\Factories\GalleryImageFactory;

class GalleryImage extends Model
{
    /** @use HasFactory<GalleryImageFactory> */
    use HasFactory;

    protected $fillable = [
        'disk',
        'path',
        'visibility',
        'original_filename',
        'title',
        'alt_text',
        'mime_type',
        'size',
        'original_size',
        'width',
        'height',
        'focal_x',
        'focal_y',
        'sort_order',
    ];

    protected $attributes = [
        'visibility' => 'public',
        'sort_order' => 0,
    ];

    public function getTable(): string
    {
        return (string) config('filament-image-gallery.tables.images', 'image_gallery_images');
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'original_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'sort_order' => 'integer',
        ];
    }

    protected function savingsPercentage(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->original_size <= 0) {
                return 0;
            }

            return max(0, round((1 - ($this->size / $this->original_size)) * 100, 1));
        });
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(config('filament-image-gallery.models.gallery', Gallery::class));
    }

    public function uploader(): MorphTo
    {
        return $this->morphTo();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(config('filament-image-gallery.models.role', GalleryImageRole::class), 'image_id');
    }

    public function url(): string
    {
        $disk = Storage::disk($this->disk);

        if ($this->visibility === 'private') {
            return $disk->temporaryUrl(
                $this->path,
                now()->addMinutes((int) config('filament-image-gallery.temporary_url_expiration', 60)),
            );
        }

        return $disk->url($this->path);
    }

    protected static function newFactory(): GalleryImageFactory
    {
        return GalleryImageFactory::new();
    }
}
