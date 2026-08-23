<?php

namespace App\Console\Commands;

use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Housekeeping for uploaded images: drops the files of already reviewed price
 * photos and every image file no product, category or other record points at.
 * Scheduled every 10 days (see routes/console.php).
 */
class PruneOrphanImages extends Command
{
    protected $signature = 'images:prune-orphans
        {--disk=public : Storage disk to sweep}
        {--min-age-hours=24 : Skip files touched more recently than this, so in-flight uploads survive}
        {--dry-run : Only report what would be deleted}';

    protected $description = 'Delete reviewed price photos and image files that are no longer referenced by any record';

    /**
     * Directories on the disk that only ever hold record-owned images. Anything
     * outside of them (documents, for instance) is left alone.
     *
     * @var array<int, string>
     */
    private const DIRECTORIES = [
        'products',
        'product-categories',
        'supermarket-products',
        'supermarket-photos',
        'supermarkets',
        'supplier-products',
        'producer-logos',
    ];

    /**
     * Columns holding a path on the swept disk, as [table => column].
     *
     * @var array<string, string>
     */
    private const PATH_COLUMNS = [
        'products' => 'image_path',
        'product_categories' => 'image_path',
        'supermarket_products' => 'image_path',
        'supplier_products' => 'image_path',
        'supermarket_price_photos' => 'path',
        'customers' => 'logo',
        'suppliers' => 'logo_path',
    ];

    public function handle(): int
    {
        $disk = (string) $this->option('disk');
        $isDryRun = (bool) $this->option('dry-run');
        $storage = Storage::disk($disk);

        $purged = $this->purgeReviewedPricePhotos($isDryRun);
        $this->info($isDryRun
            ? "{$purged} reviewed price photo file(s) would be purged."
            : "Purged {$purged} reviewed price photo file(s).");

        $referenced = $this->referencedPaths($disk);
        $orphans = $this->orphanFiles($storage, $referenced);

        if ($orphans === []) {
            $this->info('No orphan image files found.');

            return self::SUCCESS;
        }

        foreach ($orphans as $path) {
            $this->line($path);
        }

        if ($isDryRun) {
            $this->warn(count($orphans).' orphan file(s) found. Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        $storage->delete($orphans);
        $this->info('Deleted '.count($orphans).' orphan file(s).');

        return self::SUCCESS;
    }

    /**
     * Reviewed photos have had their prices extracted, so the picture itself is
     * dead weight. Normally the review page purges it on save; this catches the
     * ones saved before that and any purge that failed.
     */
    private function purgeReviewedPricePhotos(bool $isDryRun): int
    {
        $photos = SupermarketPricePhoto::query()
            ->where('status', SupermarketPricePhoto::STATUS_DONE)
            ->whereNotNull('path')
            ->get();

        if ($isDryRun) {
            return $photos->count();
        }

        return $photos->filter(fn (SupermarketPricePhoto $photo): bool => $photo->purgeFile())->count();
    }

    /**
     * Every path currently claimed by a record, normalised the same way as the
     * paths reported by the disk.
     *
     * @return array<string, true>
     */
    private function referencedPaths(string $disk): array
    {
        $paths = [];

        foreach (self::PATH_COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                $paths[] = $path;
            }
        }

        $imagesTable = (string) config('filament-image-gallery.tables.images', 'image_gallery_images');

        if (Schema::hasTable($imagesTable)) {
            foreach (DB::table($imagesTable)->where('disk', $disk)->pluck('path') as $path) {
                $paths[] = $path;
            }
        }

        $normalised = [];

        foreach ($paths as $path) {
            $path = $this->normalisePath((string) $path);

            if ($path !== '') {
                $normalised[$path] = true;
            }
        }

        return $normalised;
    }

    /**
     * @param  array<string, true>  $referenced
     * @return array<int, string>
     */
    private function orphanFiles(Filesystem $storage, array $referenced): array
    {
        $cutoff = now()->subHours((int) $this->option('min-age-hours'))->getTimestamp();
        $directories = array_merge(self::DIRECTORIES, [
            trim((string) config('filament-image-gallery.directory', 'image-gallery'), '/'),
        ]);

        $orphans = [];

        foreach (array_unique($directories) as $directory) {
            if (! $storage->directoryExists($directory)) {
                continue;
            }

            foreach ($storage->allFiles($directory) as $path) {
                if (isset($referenced[$this->normalisePath($path)])) {
                    continue;
                }

                if ($storage->lastModified($path) > $cutoff) {
                    continue;
                }

                $orphans[] = $path;
            }
        }

        return $orphans;
    }

    /**
     * Stored values are relative disk paths, but a leading slash or a "storage/"
     * prefix creeps in often enough to be worth stripping. Remote URLs never
     * match a local file, so they are dropped.
     */
    private function normalisePath(string $path): string
    {
        $path = trim($path);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return '';
        }

        $path = ltrim($path, '/');

        return Str::startsWith($path, 'storage/')
            ? Str::after($path, 'storage/')
            : $path;
    }
}
