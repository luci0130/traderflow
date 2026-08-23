<?php

namespace Turiac\FilamentImageGallery;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Turiac\FilamentImageGallery\Commands\PruneOrphanImagesCommand;
use Turiac\FilamentImageGallery\Contracts\GalleryRepository;
use Turiac\FilamentImageGallery\Services\GalleryPersister;

class FilamentImageGalleryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GalleryRepository::class, GalleryPersister::class);

        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-image-gallery.php',
            'filament-image-gallery',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneOrphanImagesCommand::class]);
        }

        FilamentAsset::register([
            AlpineComponent::make(
                'image-gallery',
                __DIR__.'/../resources/js/image-gallery.js',
            ),
            AlpineComponent::make(
                'image-gallery-focal-point',
                __DIR__.'/../resources/js/image-gallery-focal-point.js',
            ),
        ], package: 'turiac/filament-image-gallery');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-image-gallery');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-image-gallery');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/filament-image-gallery.php' => config_path('filament-image-gallery.php'),
        ], 'filament-image-gallery-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/filament-image-gallery'),
        ], 'filament-image-gallery-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/filament-image-gallery'),
        ], 'filament-image-gallery-translations');
    }
}
