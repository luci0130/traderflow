<?php

namespace Turiac\FilamentImageGallery\Support;

final readonly class ProcessedImage
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public int $size,
        public int $originalSize,
        public int $width,
        public int $height,
        public string $originalFilename,
    ) {}
}
