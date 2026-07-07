<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Downloads an image from a URL, optimizes it (square, downscaled, WebP) and
 * stores it on a disk. Shared by the "paste an image URL" action on categories.
 */
class RemoteImage
{
    public function __construct(private ImageOptimizer $optimizer) {}

    /**
     * Fetch $url, optimize it and store it under $directory/$baseName.$format.
     * Returns the stored path. Throws if the download fails or the response is
     * not a usable image.
     *
     * @throws RuntimeException
     */
    public function storeSquare(
        string $url,
        string $directory,
        string $baseName,
        string $disk = 'public',
        int $size = 400,
        int $quality = 80,
        string $format = 'webp',
    ): string {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ])->timeout(30)->retry(2, 500, throw: false)->get($url);

        if ($response->failed()) {
            throw new RuntimeException("The image could not be downloaded (HTTP {$response->status()}).");
        }

        $binary = $response->body();

        if ($binary === '') {
            throw new RuntimeException('The download returned an empty response.');
        }

        try {
            $optimized = $this->optimizer->toSquare($binary, $size, $quality, $format);
        } catch (Throwable $e) {
            throw new RuntimeException('The URL does not point to a readable image.', previous: $e);
        }

        $path = trim($directory, '/')."/{$baseName}.{$format}";
        Storage::disk($disk)->put($path, $optimized);

        return $path;
    }
}
