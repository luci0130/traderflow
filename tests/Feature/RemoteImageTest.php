<?php

namespace Tests\Feature;

use App\Support\RemoteImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use RuntimeException;
use Tests\TestCase;

class RemoteImageTest extends TestCase
{
    public function test_it_downloads_optimizes_and_stores_a_square_image(): void
    {
        Storage::fake('public');

        Http::fake([
            'example.com/*' => Http::response($this->sourceJpeg(1200), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $path = app(RemoteImage::class)->storeSquare('https://example.com/photo.jpg', 'product-categories', 'legume-abc123');

        $this->assertSame('product-categories/legume-abc123.webp', $path);
        Storage::disk('public')->assertExists($path);

        $image = new Imagick;
        $image->readImageBlob(Storage::disk('public')->get($path));
        $this->assertSame(400, $image->getImageWidth());
        $this->assertSame(400, $image->getImageHeight());
        $this->assertSame('WEBP', strtoupper($image->getImageFormat()));
        $image->clear();
    }

    public function test_it_throws_when_the_download_fails(): void
    {
        Storage::fake('public');
        Http::fake(['example.com/*' => Http::response('', 404)]);

        $this->expectException(RuntimeException::class);

        app(RemoteImage::class)->storeSquare('https://example.com/missing.jpg', 'product-categories', 'x');
    }

    public function test_it_throws_when_the_url_is_not_an_image(): void
    {
        Storage::fake('public');
        Http::fake(['example.com/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(RuntimeException::class);

        app(RemoteImage::class)->storeSquare('https://example.com/page.html', 'product-categories', 'x');
    }

    private function sourceJpeg(int $size): string
    {
        $image = new Imagick;
        $image->newImage($size, (int) ($size * 0.75), '#3aa757');
        $image->setImageFormat('jpeg');

        $blob = $image->getImageBlob();
        $image->clear();

        return $blob;
    }
}
