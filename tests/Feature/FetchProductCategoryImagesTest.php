<?php

namespace Tests\Feature;

use App\Modules\ProductCategories\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Tests\TestCase;

class FetchProductCategoryImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_categories_by_name_and_stores_optimized_images(): void
    {
        Storage::fake('public');

        $listing = 'https://www.freshful.ro/test-listing';
        $html = $this->productCard('Cireșe România 1kg', 'aa/bb/cirese.jpg')
            .$this->productCard('Zmeură România 125g', 'cc/dd/zmeura.jpg');

        Http::fake([
            'www.freshful.ro/*' => Http::response($html),
            'cdn.freshful.ro/*' => Http::response($this->sourceJpeg(1200), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $cirese = ProductCategory::create(['name' => 'Cireșe']);
        $zmeura = ProductCategory::create(['name' => 'Zmeură']);
        $noMatch = ProductCategory::create(['name' => 'Cartofi pentru piure']);

        $this->artisan('product-categories:fetch-images', ['--url' => [$listing]])
            ->assertSuccessful();

        foreach ([$cirese, $zmeura] as $category) {
            $category->refresh();
            $this->assertNotNull($category->image_path);
            $this->assertStringEndsWith('.webp', $category->image_path);
            Storage::disk('public')->assertExists($category->image_path);

            // The stored file is the optimized 400×400 square, not the 1200px source.
            $image = new Imagick;
            $image->readImageBlob(Storage::disk('public')->get($category->image_path));
            $this->assertSame(400, $image->getImageWidth());
            $this->assertSame(400, $image->getImageHeight());
            $image->clear();
        }

        $this->assertNull($noMatch->refresh()->image_path);
    }

    public function test_it_skips_categories_that_already_have_an_image_without_overwrite(): void
    {
        Storage::fake('public');

        Http::fake([
            'www.freshful.ro/*' => Http::response($this->productCard('Cireșe România 1kg', 'aa/bb/cirese.jpg')),
            'cdn.freshful.ro/*' => Http::response($this->sourceJpeg(600), 200),
        ]);

        $category = ProductCategory::create([
            'name' => 'Cireșe',
            'image_path' => 'product-categories/existing.webp',
        ]);

        $this->artisan('product-categories:fetch-images', ['--url' => ['https://www.freshful.ro/x']])
            ->assertSuccessful();

        $this->assertSame('product-categories/existing.webp', $category->refresh()->image_path);
    }

    private function productCard(string $name, string $imagePath): string
    {
        $alt = htmlspecialchars($name, ENT_QUOTES);

        return '<div class="card"><img alt="'.$alt.'" loading="lazy" width="400" height="400" '
            .'src="https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/'.$imagePath.'"/></div>';
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
