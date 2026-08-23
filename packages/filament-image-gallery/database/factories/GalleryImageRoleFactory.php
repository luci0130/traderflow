<?php

namespace Turiac\FilamentImageGallery\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Turiac\FilamentImageGallery\Models\Gallery;
use Turiac\FilamentImageGallery\Models\GalleryImage;
use Turiac\FilamentImageGallery\Models\GalleryImageRole;

/** @extends Factory<GalleryImageRole> */
class GalleryImageRoleFactory extends Factory
{
    protected $model = GalleryImageRole::class;

    public function definition(): array
    {
        return [
            'gallery_id' => Gallery::factory(),
            'image_id' => GalleryImage::factory(),
            'role' => fake()->unique()->randomElement(['main', 'base', 'small', 'thumbnail']),
        ];
    }
}
