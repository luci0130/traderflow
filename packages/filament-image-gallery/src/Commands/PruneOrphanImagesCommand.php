<?php

namespace Turiac\FilamentImageGallery\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Turiac\FilamentImageGallery\Models\GalleryImage;

class PruneOrphanImagesCommand extends Command
{
    protected $signature = 'image-gallery:prune
        {--disk= : Storage disk to inspect}
        {--directory= : Gallery directory to inspect}
        {--delete : Permanently delete orphan files}';

    protected $description = 'List or delete unreferenced generated image-gallery files';

    public function handle(): int
    {
        $disk = (string) ($this->option('disk') ?: config('filament-image-gallery.disk', 'public'));
        $directory = trim((string) ($this->option('directory') ?: config('filament-image-gallery.directory', 'image-gallery')), '/');
        $storage = Storage::disk($disk);
        $candidatePaths = collect($storage->allFiles($directory))
            ->filter(fn (string $path): bool => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:gif|jpe?g|png|webp)$/i',
                basename($path),
            ) === 1)
            ->values();
        $imageClass = config('filament-image-gallery.models.image', GalleryImage::class);
        $referencedPaths = $imageClass::query()
            ->where('disk', $disk)
            ->whereIn('path', $candidatePaths->all())
            ->pluck('path')
            ->all();
        $orphans = $candidatePaths->diff($referencedPaths)->values();

        if ($orphans->isEmpty()) {
            $this->info('No orphan gallery files found.');

            return self::SUCCESS;
        }

        foreach ($orphans as $path) {
            $this->line($path);
        }

        if (! $this->option('delete')) {
            $this->warn("Found {$orphans->count()} orphan file(s). This was a dry run; pass --delete to remove them.");

            return self::SUCCESS;
        }

        $storage->delete($orphans->all());
        $this->info("Deleted {$orphans->count()} orphan file(s).");

        return self::SUCCESS;
    }
}
