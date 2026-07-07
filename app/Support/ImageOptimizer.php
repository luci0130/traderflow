<?php

namespace App\Support;

use Imagick;

/**
 * Turns arbitrary source image bytes into a small, square, metadata-free
 * thumbnail. Used to optimize category pictures the same way whether they come
 * from a local folder or are downloaded from an external catalog.
 */
class ImageOptimizer
{
    /**
     * Cover-crop to a centered square, downscale to $size, strip metadata and
     * re-encode. Returns the optimized image binary.
     */
    public function toSquare(string $binary, int $size = 400, int $quality = 80, string $format = 'webp'): string
    {
        $image = new Imagick;
        $image->readImageBlob($binary);

        $this->applyOrientation($image);

        $image->setImageBackgroundColor('white');
        $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        // cropThumbnailImage scales then centre-crops to exactly size×size (cover).
        $image->cropThumbnailImage($size, $size);
        $image->stripImage();
        $image->setImageFormat($format === 'jpg' ? 'jpeg' : 'webp');
        $image->setImageCompressionQuality($quality);

        $blob = $image->getImageBlob();
        $image->clear();

        return $blob;
    }

    /**
     * Rotate the image upright from its EXIF orientation, then reset the flag.
     * Done manually because autoOrientImage() is absent from some Imagick builds.
     */
    private function applyOrientation(Imagick $image): void
    {
        switch ($image->getImageOrientation()) {
            case Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
        }

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
