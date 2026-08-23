<?php

namespace Turiac\FilamentImageGallery\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class RemoteImageDownloader
{
    public function download(string $url, int $maxBytes, int $maxRedirects = 5): TemporaryUploadedFile
    {
        $this->enforceRateLimit();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'fig-url-');

        if ($temporaryPath === false) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.download_failed'));
        }

        try {
            $currentUrl = $url;

            for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
                [$host, $ip] = $this->validateUrl($currentUrl);
                $response = $this->request($currentUrl, $temporaryPath, $host, $ip, $maxBytes);

                if (! $response->redirect()) {
                    $response->throw();

                    $size = filesize($temporaryPath);
                    $imageInfo = @getimagesize($temporaryPath);

                    if ($size === false || $size > $maxBytes || $imageInfo === false || ! in_array($imageInfo['mime'] ?? null, [
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                        'image/gif',
                    ], true)) {
                        throw new RuntimeException(__('filament-image-gallery::gallery.validation.invalid_image'));
                    }

                    if (
                        (int) $imageInfo[0] > (int) config('filament-image-gallery.max_width', 5000)
                        || (int) $imageInfo[1] > (int) config('filament-image-gallery.max_height', 5000)
                    ) {
                        throw new RuntimeException(__('filament-image-gallery::gallery.validation.dimensions', [
                            'width' => config('filament-image-gallery.max_width', 5000),
                            'height' => config('filament-image-gallery.max_height', 5000),
                        ]));
                    }

                    return $this->toTemporaryUpload($temporaryPath, $currentUrl, (string) $imageInfo['mime']);
                }

                if ($redirect === $maxRedirects) {
                    throw new RuntimeException(__('filament-image-gallery::gallery.validation.too_many_redirects'));
                }

                $location = $response->header('Location');

                if (blank($location)) {
                    throw new RuntimeException(__('filament-image-gallery::gallery.validation.download_failed'));
                }

                $currentUrl = $this->resolveRedirect($currentUrl, $location);
            }

            throw new RuntimeException(__('filament-image-gallery::gallery.validation.download_failed'));
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function request(string $url, string $sink, string $host, string $ip, int $maxBytes): Response
    {
        if (! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_PROTOCOLS') || ! defined('CURLPROTO_HTTPS')) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.download_unavailable'));
        }

        return Http::connectTimeout(10)
            ->timeout(30)
            ->withOptions([
                'allow_redirects' => false,
                'sink' => $sink,
                'curl' => [
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_RESOLVE => ["{$host}:443:{$ip}"],
                ],
                'progress' => static function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                    if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) {
                        throw new RuntimeException(__('filament-image-gallery::gallery.validation.max_size', [
                            'size' => $maxBytes / 1024 / 1024,
                        ]));
                    }
                },
            ])
            ->get($url);
    }

    /**
     * @return array{string, string}
     */
    private function validateUrl(string $url): array
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null) || (($parts['port'] ?? 443) !== 443)) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.https_url'));
        }

        $host = strtolower((string) $parts['host']);
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_unique(array_filter([
                ...array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
                ...array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            ])));

        if ($addresses === []) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.download_failed'));
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException(__('filament-image-gallery::gallery.validation.public_url'));
            }
        }

        return [$host, $addresses[0]];
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (Str::startsWith($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        $origin = 'https://'.$parts['host'];

        if (Str::startsWith($location, '/')) {
            return $origin.$location;
        }

        $directory = trim(dirname($parts['path'] ?? '/'), '/.');

        return $origin.'/'.($directory === '' ? '' : $directory.'/').$location;
    }

    private function toTemporaryUpload(string $path, string $url, string $mimeType): TemporaryUploadedFile
    {
        $filename = basename((string) parse_url($url, PHP_URL_PATH));
        $name = Str::of($filename !== '' ? $filename : 'remote-image')->beforeLast('.')->value();
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new RuntimeException(__('filament-image-gallery::gallery.validation.invalid_image')),
        };
        $filename = Str::limit($name, 240, '').'.'.$extension;
        $uploadedFile = new UploadedFile($path, $filename, $mimeType, null, true);
        $storedPath = FileUploadConfiguration::storeTemporaryFile($uploadedFile, FileUploadConfiguration::disk());

        return TemporaryUploadedFile::createFromLivewire(basename($storedPath));
    }

    private function enforceRateLimit(): void
    {
        $user = auth()->user();
        $identity = $user instanceof Model
            ? $user->getMorphClass().':'.$user->getKey()
            : request()->ip();
        $key = 'filament-image-gallery:remote:'.hash('sha256', (string) $identity);
        $maximumAttempts = (int) config('filament-image-gallery.remote.max_attempts_per_minute', 10);

        if (RateLimiter::tooManyAttempts($key, $maximumAttempts)) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.rate_limited'));
        }

        RateLimiter::hit($key, 60);
    }
}
