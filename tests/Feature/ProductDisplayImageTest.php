<?php

namespace Tests\Feature;

use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDisplayImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_uses_its_own_image_when_set(): void
    {
        $category = ProductCategory::create(['name' => 'Legume', 'image_path' => 'product-categories/legume.webp']);
        $product = Product::create([
            'name' => 'Roșii',
            'status' => 'active',
            'product_category_id' => $category->id,
            'image_path' => 'products/rosii.webp',
        ]);

        $this->assertSame('products/rosii.webp', $product->display_image_path);
        $this->assertStringContainsString('products/rosii.webp', $product->displayImageUrl());
    }

    public function test_product_prefers_the_primary_gallery_image(): void
    {
        $category = ProductCategory::create(['name' => 'Legume', 'image_path' => 'product-categories/legume.webp']);
        $product = Product::create([
            'name' => 'Roșii',
            'status' => 'active',
            'product_category_id' => $category->id,
            'image_path' => 'products/legacy.webp',
        ]);

        $gallery = $product->imageGalleries()->create(['collection' => 'products']);
        $image = $gallery->images()->create([
            'disk' => 'public',
            'path' => 'products/gallery.webp',
            'visibility' => 'public',
            'original_filename' => 'gallery.webp',
            'title' => 'Gallery',
            'mime_type' => 'image/webp',
            'size' => 100,
            'original_size' => 100,
            'width' => 100,
            'height' => 100,
            'sort_order' => 1,
        ]);
        $image->roles()->create(['gallery_id' => $gallery->id, 'role' => 'main']);

        $this->assertSame('products/gallery.webp', $product->display_image_path);
        $this->assertStringContainsString('products/gallery.webp', $product->displayImageUrl());
    }

    public function test_product_falls_back_to_the_category_image_when_it_has_none(): void
    {
        $category = ProductCategory::create(['name' => 'Legume', 'image_path' => 'product-categories/legume.webp']);
        $product = Product::create([
            'name' => 'Roșii',
            'status' => 'active',
            'product_category_id' => $category->id,
        ]);

        $this->assertSame('product-categories/legume.webp', $product->display_image_path);
        $this->assertStringContainsString('product-categories/legume.webp', $product->displayImageUrl());
    }

    public function test_display_image_is_null_when_neither_product_nor_category_has_one(): void
    {
        $categoryWithoutImage = ProductCategory::create(['name' => 'Legume']);
        $product = Product::create([
            'name' => 'Roșii',
            'status' => 'active',
            'product_category_id' => $categoryWithoutImage->id,
        ]);
        $orphan = Product::create(['name' => 'Fără categorie', 'status' => 'active']);

        $this->assertNull($product->display_image_path);
        $this->assertNull($product->displayImageUrl());
        $this->assertNull($orphan->display_image_path);
        $this->assertNull($orphan->displayImageUrl());
    }
}
