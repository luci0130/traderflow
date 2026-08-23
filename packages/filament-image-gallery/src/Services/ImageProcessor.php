<?php

namespace Turiac\FilamentImageGallery\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Turiac\FilamentImageGallery\Support\ProcessedImage;

class ImageProcessor
{
    /**
     * @var array<string, true>
     */
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
        'image/gif' => true,
    ];

    public function processStored(
        string $sourcePath,
        string $disk,
        string $directory,
        string $visibility,
        bool $optimize,
        int $quality,
        int $maxSizeKilobytes,
        int $maxWidth,
        int $maxHeight,
        ?int $targetWidth = null,
        ?int $targetHeight = null,
    ): ProcessedImage {
        $storage = Storage::disk($disk);
        $sourceSize = $storage->size($sourcePath);

        if ($sourceSize > ($maxSizeKilobytes * 1024)) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.max_size', [
                'size' => $maxSizeKilobytes / 1024,
            ]));
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'fig-source-');

        if ($temporaryPath === false) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
        }

        $sourceStream = $storage->readStream($sourcePath);
        $temporaryStream = fopen($temporaryPath, 'wb');

        if ($sourceStream === false || $temporaryStream === false) {
            is_resource($sourceStream) && fclose($sourceStream);
            is_resource($temporaryStream) && fclose($temporaryStream);
            @unlink($temporaryPath);

            throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
        }

        try {
            $copiedBytes = stream_copy_to_stream($sourceStream, $temporaryStream);
        } finally {
            fclose($sourceStream);
            fclose($temporaryStream);
        }

        if ($copiedBytes === false) {
            @unlink($temporaryPath);

            throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
        }

        try {
            return $this->process(
                file: new UploadedFile(
                    $temporaryPath,
                    basename($sourcePath),
                    $storage->mimeType($sourcePath) ?: null,
                    UPLOAD_ERR_OK,
                    true,
                ),
                disk: $disk,
                directory: $directory,
                visibility: $visibility,
                optimize: $optimize,
                quality: $quality,
                maxSizeKilobytes: $maxSizeKilobytes,
                maxWidth: $maxWidth,
                maxHeight: $maxHeight,
                targetWidth: $targetWidth,
                targetHeight: $targetHeight,
            );
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function process(
        UploadedFile $file,
        string $disk,
        string $directory,
        string $visibility,
        bool $optimize,
        int $quality,
        int $maxSizeKilobytes,
        int $maxWidth,
        int $maxHeight,
        ?int $targetWidth = null,
        ?int $targetHeight = null,
    ): ProcessedImage {
        $originalSize = $file->getSize();

        if ($originalSize === false || $originalSize > ($maxSizeKilobytes * 1024)) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.max_size', [
                'size' => $maxSizeKilobytes / 1024,
            ]));
        }

        $sourcePath = $file->getRealPath();

        if ($sourcePath === false) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.invalid_image'));
        }

        $imageInfo = @getimagesize($sourcePath);

        if ($imageInfo === false || ! isset(self::SUPPORTED_MIME_TYPES[$imageInfo['mime'] ?? ''])) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.invalid_image'));
        }

        [$width, $height] = $imageInfo;

        if ($width > $maxWidth || $height > $maxHeight) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.dimensions', [
                'width' => $maxWidth,
                'height' => $maxHeight,
            ]));
        }

        $this->guardAvailableMemory($width, $height, $targetWidth !== null || $targetHeight !== null);

        $directory = trim($directory, '/');
        $originalFilename = $file->getClientOriginalName();

        if (! $optimize && ! config('filament-image-gallery.strip_metadata', true) && $targetWidth === null && $targetHeight === null) {
            $extension = $this->extensionForMimeType((string) $imageInfo['mime']);
            $path = $directory.'/'.Str::uuid().'.'.$extension;
            $stream = fopen($sourcePath, 'rb');

            if ($stream === false) {
                throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
            }

            try {
                $wasStored = Storage::disk($disk)->put($path, $stream, ['visibility' => $visibility]);
            } finally {
                fclose($stream);
            }

            if (! $wasStored) {
                throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
            }

            return new ProcessedImage(
                path: $path,
                mimeType: (string) $imageInfo['mime'],
                size: (int) $originalSize,
                originalSize: $originalSize,
                width: $width,
                height: $height,
                originalFilename: $originalFilename,
            );
        }

        $image = match ($imageInfo['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.invalid_image'));
        }

        $image = $this->orientImage($image, $sourcePath, (string) $imageInfo['mime']);
        $image = $this->resizeImage($image, $targetWidth, $targetHeight);

        if (! imageistruecolor($image) && ! imagepalettetotruecolor($image)) {
            imagedestroy($image);

            throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
        }

        $width = imagesx($image);
        $height = imagesy($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'fig-');

        if ($temporaryPath === false) {
            imagedestroy($image);

            throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
        }

        try {
            $outputMimeType = $optimize ? 'image/webp' : (string) $imageInfo['mime'];
            $wasWritten = match ($outputMimeType) {
                'image/jpeg' => imagejpeg($image, $temporaryPath, max(0, min(100, $quality))),
                'image/png' => imagepng($image, $temporaryPath),
                'image/webp' => imagewebp($image, $temporaryPath, max(0, min(100, $quality))),
                'image/gif' => imagegif($image, $temporaryPath),
            };

            if (! $wasWritten) {
                throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
            }

            $extension = match ($outputMimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            };
            $path = $directory.'/'.Str::uuid().'.'.$extension;
            $stream = fopen($temporaryPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
            }

            try {
                $wasStored = Storage::disk($disk)->put($path, $stream, ['visibility' => $visibility]);
            } finally {
                fclose($stream);
            }

            if (! $wasStored) {
                throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
            }

            $outputSize = filesize($temporaryPath);

            if ($outputSize === false) {
                Storage::disk($disk)->delete($path);

                throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_failed'));
            }

            return new ProcessedImage(
                path: $path,
                mimeType: $outputMimeType,
                size: $outputSize,
                originalSize: $originalSize,
                width: $width,
                height: $height,
                originalFilename: $originalFilename,
            );
        } finally {
            imagedestroy($image);
            @unlink($temporaryPath);
        }
    }

    private function orientImage(GdImage $image, string $sourcePath, string $mimeType): GdImage
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($sourcePath)['Orientation'] ?? 1;

        $orientedImage = match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };

        if ($orientedImage !== $image) {
            imagedestroy($image);
        }

        return $orientedImage;
    }

    private function resizeImage(GdImage $image, ?int $targetWidth, ?int $targetHeight): GdImage
    {
        if ($targetWidth === null && $targetHeight === null) {
            return $image;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(
            1,
            $targetWidth !== null ? $targetWidth / $width : 1,
            $targetHeight !== null ? $targetHeight / $height : 1,
        );
        $resizedWidth = max(1, (int) floor($width * $scale));
        $resizedHeight = max(1, (int) floor($height * $scale));

        if ($resizedWidth === $width && $resizedHeight === $height) {
            return $image;
        }

        $resizedImage = imagecreatetruecolor($resizedWidth, $resizedHeight);
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);

        if (! imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $resizedWidth, $resizedHeight, $width, $height)) {
            imagedestroy($resizedImage);

            return $image;
        }

        imagedestroy($image);

        return $resizedImage;
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new RuntimeException(__('filament-image-gallery::gallery.validation.invalid_image')),
        };
    }

    private function guardAvailableMemory(int $width, int $height, bool $willResize): void
    {
        $memoryLimit = $this->memoryLimitInBytes();

        if ($memoryLimit === null) {
            return;
        }

        $estimatedBytes = (int) ceil($width * $height * ($willResize ? 8 : 5.5));
        $availableBytes = $memoryLimit - memory_get_usage(true);

        if ($estimatedBytes > ($availableBytes * 0.8)) {
            throw new RuntimeException(__('filament-image-gallery::gallery.validation.processing_memory'));
        }
    }

    private function memoryLimitInBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
