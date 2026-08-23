<?php

namespace Turiac\FilamentImageGallery\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Turiac\FilamentImageGallery\Models\Gallery;

/** @extends Factory<Gallery> */
class GalleryFactory extends Factory
{
    protected $model = Gallery::class;

    public function definition(): array
    {
        return [
            'owner_type' => 'test-owner',
            'owner_id' => fake()->randomNumber(),
            'collection' => fake()->word(),
        ];
    }
}
