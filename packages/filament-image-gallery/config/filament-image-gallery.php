<?php

use Turiac\FilamentImageGallery\Models\Gallery;
use Turiac\FilamentImageGallery\Models\GalleryImage;
use Turiac\FilamentImageGallery\Models\GalleryImageRole;

return [
    'models' => [
        'gallery' => Gallery::class,
        'image' => GalleryImage::class,
        'role' => GalleryImageRole::class,
    ],
    'tables' => [
        'galleries' => 'image_galleries',
        'images' => 'image_gallery_images',
        'roles' => 'image_gallery_roles',
    ],
    'morph_key_type' => 'id',
    'disk' => 'public',
    'visibility' => 'public',
    'directory' => 'image-gallery',
    'optimize_images' => true,
    'strip_metadata' => true,
    'webp_quality' => 82,
    'max_size' => 10240,
    'max_width' => 5000,
    'max_height' => 5000,
    'max_images' => 10,
    'max_parallel_uploads' => 4,
    'allow_minimum_upload_duration' => false,
    'temporary_url_expiration' => 60,
    'remote' => [
        'max_attempts_per_minute' => 10,
    ],
    'roles' => [
        'main' => 'filament-image-gallery::gallery.roles.main',
        'base' => 'filament-image-gallery::gallery.roles.base',
        'small' => 'filament-image-gallery::gallery.roles.small',
        'thumbnail' => 'filament-image-gallery::gallery.roles.thumbnail',
    ],
    'default_roles' => ['main', 'base', 'small', 'thumbnail'],
    'tools' => ['information', 'crop', 'resize', 'replace'],
];
