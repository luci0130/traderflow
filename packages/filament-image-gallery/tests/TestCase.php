<?php

namespace Turiac\FilamentImageGallery\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Turiac\FilamentImageGallery\FilamentImageGalleryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FilamentImageGalleryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('filesystems.disks.testing', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/image-gallery'),
            'throw' => false,
        ]);
        $app['config']->set('filament-image-gallery.disk', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('test_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        (require __DIR__.'/../database/migrations/2026_08_17_000000_create_filament_image_gallery_tables.php')->up();
        (require __DIR__.'/../database/migrations/2026_08_19_000001_harden_filament_image_gallery_indexes.php')->up();
    }
}
