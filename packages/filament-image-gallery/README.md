# Filament Image Gallery

A role-aware image gallery form component for Laravel 13, Filament 5, and Livewire 4.

## Requirements

- PHP 8.3+
- Laravel 13
- Filament 5
- Livewire 4.1+
- cURL, Fileinfo, and GD extensions

## Installation

Install the commercial Composer package, publish its configuration, and run the migrations:

```bash
composer require turiac/filament-image-gallery
php artisan vendor:publish --tag=filament-image-gallery-config
php artisan migrate
```

Register the plugin on each Filament panel that uses it:

```php
use Turiac\FilamentImageGallery\FilamentImageGalleryPlugin;

$panel->plugin(
    FilamentImageGalleryPlugin::make()
        ->disk('public')
        ->directory('image-gallery')
        ->maxImages(10)
        ->roles([
            'main' => 'Main',
            'base' => 'Base',
            'small' => 'Small',
            'thumbnail' => 'Thumbnail',
        ])
        ->defaultRoles(['main', 'base', 'small', 'thumbnail'])
        ->tools(['information', 'crop', 'resize', 'replace']),
);
```

Add `HasImageGalleries` to an Eloquent owner and use the field in its Filament form:

```php
use Turiac\FilamentImageGallery\Concerns\HasImageGalleries;
use Turiac\FilamentImageGallery\Forms\Components\ImageGallery;

class Product extends Model
{
    use HasImageGalleries;
}

ImageGallery::make('images')
    ->collection('products')
    ->directory('products');
```

Field-level settings override panel settings, which override `config/filament-image-gallery.php`.

New uploads are converted to WebP by default, EXIF metadata is stripped, images are never enlarged, and remote URLs are restricted to public HTTPS addresses. Setting `optimize_images` to `false` preserves the verified source format; metadata stripping still re-encodes the image unless `strip_metadata` is also disabled. Animated GIF uploads intentionally use their first frame.

Inspect unreferenced generated files with `php artisan image-gallery:prune`. The command is a dry run by default; pass `--delete` only after reviewing its output.

This package is proprietary commercial software. Distribution and update rights are governed by the license supplied with a purchase.
