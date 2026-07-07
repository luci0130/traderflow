<?php

namespace App\Console\Commands;

use App\Modules\ProductCategories\Models\ProductCategory;
use App\Support\FreshfulCatalog;
use App\Support\ImageOptimizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Best-effort populate product categories with photos scraped from Freshful's
 * public listing pages, matched by Romanian name and run through the same
 * optimizer as the folder import (square, downscaled, WebP). Intended for
 * internal use — see the licensing caveat in the command output.
 */
#[Signature('product-categories:fetch-images
    {--url=* : Freshful listing URLs to scrape (defaults to fruits & vegetables)}
    {--size=400 : Width and height (px) of the square output}
    {--quality=80 : Output compression quality (1-100)}
    {--format=webp : Output format: webp or jpg}
    {--tenant= : Only match categories belonging to this tenant id}
    {--overwrite : Replace images on categories that already have one}
    {--dry-run : Report matches without downloading or writing anything}')]
#[Description('Fetch optimized product-category images from Freshful public listings.')]
class FetchProductCategoryImages extends Command
{
    private const DEFAULT_URLS = ['https://www.freshful.ro/c/3-fructe-si-legume'];

    public function handle(FreshfulCatalog $catalog, ImageOptimizer $optimizer): int
    {
        if (! extension_loaded('imagick')) {
            $this->components->error('The imagick PHP extension is required to optimize images.');

            return self::FAILURE;
        }

        $this->components->warn('These are retailer product photos. Fine for internal use; review the licensing before putting them in client-facing offers.');

        $urls = $this->option('url') ?: self::DEFAULT_URLS;
        $products = $this->collectProducts($catalog, $urls);

        if ($products->isEmpty()) {
            $this->components->error('No products could be parsed from the listing pages.');

            return self::FAILURE;
        }

        $this->components->info("Parsed {$products->count()} products from ".count($urls).' page(s).');

        $format = strtolower((string) $this->option('format')) === 'jpg' ? 'jpg' : 'webp';
        $size = max(16, (int) $this->option('size'));
        $quality = min(100, max(1, (int) $this->option('quality')));
        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');

        $matched = 0;
        $skipped = 0;
        $unmatched = [];

        foreach ($this->categories() as $category) {
            if (filled($category->image_path) && ! $overwrite) {
                $skipped++;

                continue;
            }

            $imageUrl = $catalog->bestImageFor($category->name, $products);

            if ($imageUrl === null) {
                $unmatched[] = $category->name;

                continue;
            }

            if ($dryRun) {
                $this->components->twoColumnDetail($category->name, Str::afterLast($imageUrl, '/').' <fg=gray>(dry run)</>');
                $matched++;

                continue;
            }

            $binary = $this->download($imageUrl);

            if ($binary === null) {
                $this->components->warn("Download failed for {$category->name}");

                continue;
            }

            $path = "product-categories/{$category->getKey()}-".Str::slug($category->name).".{$format}";
            Storage::disk('public')->put($path, $optimizer->toSquare($binary, $size, $quality, $format));

            if (filled($category->image_path) && $category->image_path !== $path) {
                Storage::disk('public')->delete($category->image_path);
            }

            $category->forceFill(['image_path' => $path])->save();

            $this->components->twoColumnDetail($category->name, "→ {$path}");
            $matched++;
        }

        $this->newLine();
        $this->components->info("Matched {$matched}, skipped {$skipped} (already had an image), no match for ".count($unmatched).'.');

        if (! empty($unmatched)) {
            $this->components->warn('No product match for: '.implode(', ', $unmatched));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $urls
     * @return Collection<int, array{name: string, image: string}>
     */
    private function collectProducts(FreshfulCatalog $catalog, array $urls): Collection
    {
        return collect($urls)
            ->flatMap(function (string $url) use ($catalog): Collection {
                $response = $this->client()->get($url);

                if ($response->failed()) {
                    $this->components->warn("Could not fetch {$url} (HTTP {$response->status()})");

                    return collect();
                }

                return $catalog->parseProducts($response->body());
            })
            ->unique('image')
            ->values();
    }

    private function download(string $url): ?string
    {
        $response = $this->client()->get($url);

        return $response->successful() ? $response->body() : null;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Referer' => 'https://www.freshful.ro/',
        ])->timeout(30)->retry(2, 500, throw: false);
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    private function categories(): Collection
    {
        return ProductCategory::query()
            ->when($this->option('tenant') !== null, fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->orderBy('name')
            ->get();
    }
}
