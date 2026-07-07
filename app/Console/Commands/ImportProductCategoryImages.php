<?php

namespace App\Console\Commands;

use App\Modules\ProductCategories\Models\ProductCategory;
use App\Support\ImageOptimizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Bulk-assign an optimized picture to every product category from a folder of
 * source images. Each file is matched to a category by the slug of its name
 * (e.g. "cartofi-albi.jpg" → "Cartofi albi"), then cover-cropped to a square,
 * downscaled, stripped of metadata and re-encoded (WebP by default) so the
 * stored files stay small — the same optimization the upload widget does in the
 * browser, but for many categories at once.
 */
#[Signature('product-categories:import-images
    {source : Path to a folder containing the source images}
    {--size=400 : Width and height (px) of the square output}
    {--quality=80 : Output compression quality (1-100)}
    {--format=webp : Output format: webp or jpg}
    {--tenant= : Only match categories belonging to this tenant id}
    {--overwrite : Replace images on categories that already have one}
    {--dry-run : Report what would happen without writing anything}')]
#[Description('Populate product categories with optimized images from a source folder.')]
class ImportProductCategoryImages extends Command
{
    public function handle(ImageOptimizer $optimizer): int
    {
        if (! extension_loaded('imagick')) {
            $this->components->error('The imagick PHP extension is required to optimize images.');

            return self::FAILURE;
        }

        $source = (string) $this->argument('source');

        if (! is_dir($source)) {
            $this->components->error("Source folder not found: {$source}");

            return self::FAILURE;
        }

        $format = strtolower((string) $this->option('format')) === 'jpg' ? 'jpg' : 'webp';
        $size = max(16, (int) $this->option('size'));
        $quality = min(100, max(1, (int) $this->option('quality')));
        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');

        $categoriesBySlug = $this->categoriesBySlug();
        $files = $this->sourceImages($source);

        if ($files->isEmpty()) {
            $this->components->warn('No image files found in the source folder.');

            return self::SUCCESS;
        }

        $matched = 0;
        $skipped = 0;
        $unmatched = [];

        foreach ($files as $file) {
            $slug = Str::slug(pathinfo($file->getFilename(), PATHINFO_FILENAME));
            $categories = $categoriesBySlug->get($slug);

            if ($categories === null) {
                $unmatched[] = $file->getFilename();

                continue;
            }

            foreach ($categories as $category) {
                if (filled($category->image_path) && ! $overwrite) {
                    $skipped++;

                    continue;
                }

                $path = "product-categories/{$category->getKey()}-{$slug}.{$format}";

                if ($dryRun) {
                    $this->components->twoColumnDetail($category->name, "→ {$path} <fg=gray>(dry run)</>");
                    $matched++;

                    continue;
                }

                Storage::disk('public')->put($path, $optimizer->toSquare((string) file_get_contents($file->getRealPath()), $size, $quality, $format));

                if (filled($category->image_path) && $category->image_path !== $path) {
                    Storage::disk('public')->delete($category->image_path);
                }

                $category->forceFill(['image_path' => $path])->save();

                $this->components->twoColumnDetail($category->name, "→ {$path}");
                $matched++;
            }
        }

        $withoutImage = ProductCategory::query()
            ->when($this->option('tenant') !== null, fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->whereNull('image_path')
            ->pluck('name');

        $this->newLine();
        $this->components->info("Updated {$matched} categor".($matched === 1 ? 'y' : 'ies').", skipped {$skipped} (already had an image).");

        if (! empty($unmatched)) {
            $this->components->warn('Source files with no matching category: '.implode(', ', $unmatched));
        }

        if ($withoutImage->isNotEmpty()) {
            $this->components->warn('Categories still without an image: '.$withoutImage->implode(', '));
        }

        return self::SUCCESS;
    }

    /**
     * Categories keyed by the slug of their name (a name can map to several).
     *
     * @return Collection<string, Collection<int, ProductCategory>>
     */
    private function categoriesBySlug(): Collection
    {
        return ProductCategory::query()
            ->when($this->option('tenant') !== null, fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->get()
            ->groupBy(fn (ProductCategory $category): string => Str::slug($category->name));
    }

    /**
     * @return Collection<int, SplFileInfo>
     */
    private function sourceImages(string $source): Collection
    {
        $finder = Finder::create()
            ->files()
            ->in($source)
            ->name('/\.(jpe?g|png|webp|gif|bmp)$/i');

        return collect(iterator_to_array($finder, false));
    }
}
