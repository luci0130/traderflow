<?php

namespace Turiac\FilamentImageGallery\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Turiac\FilamentImageGallery\Models\Gallery;
use Turiac\FilamentImageGallery\Models\GalleryImage;

/** @extends Factory<GalleryImage> */
class GalleryImageFactory extends Factory
{
    protected $model = GalleryImage::class;

    public function definition(): array
    {
        $filename = Str::uuid().'.webp';

        return [
            'gallery_id' => Gallery::factory(),
            'disk' => 'public',
            'path' => 'image-gallery/'.$filename,
            'visibility' => 'public',
            'original_filename' => $filename,
            'title' => fake()->words(3, true),
            'alt_text' => fake()->sentence(),
            'mime_type' => 'image/webp',
            'size' => 1024,
            'original_size' => 2048,
            'width' => 800,
            'height' => 600,
            'sort_order' => 1,
        ];
    }
}
