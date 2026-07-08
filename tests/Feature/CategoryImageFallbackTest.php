<?php

namespace Tests\Feature;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Support\CategoryImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_matches_category_name_case_insensitively(): void
    {
        ProductCategory::create(['name' => 'Roșii', 'image_path' => 'product-categories/rosii.webp']);

        $resolver = app(CategoryImages::class);

        $this->assertSame('product-categories/rosii.webp', $resolver->pathFor('roșii'));
        $this->assertStringContainsString('product-categories/rosii.webp', $resolver->urlFor('Roșii'));
        $this->assertNull($resolver->pathFor('Necunoscut'));
        $this->assertNull($resolver->pathFor(null));
    }

    public function test_supplier_product_falls_back_to_category_image(): void
    {
        ProductCategory::create(['name' => 'Roșii', 'image_path' => 'product-categories/rosii.webp']);

        $withImage = new SupplierProduct(['category' => 'Roșii', 'image_path' => 'supplier-products/own.webp']);
        $withoutImage = new SupplierProduct(['category' => 'Roșii']);
        $unmatched = new SupplierProduct(['category' => 'Ceva necunoscut']);

        $this->assertSame('supplier-products/own.webp', $withImage->display_image_path);
        $this->assertSame('product-categories/rosii.webp', $withoutImage->display_image_path);
        $this->assertNull($unmatched->display_image_path);
    }

    public function test_supermarket_product_falls_back_to_category_image(): void
    {
        ProductCategory::create(['name' => 'Cartofi', 'image_path' => 'product-categories/cartofi.webp']);

        $withImage = new SupermarketProduct(['category' => 'Cartofi', 'image_path' => 'supermarket-products/own.webp']);
        $withoutImage = new SupermarketProduct(['category' => 'Cartofi']);

        $this->assertSame('supermarket-products/own.webp', $withImage->display_image_path);
        $this->assertSame('product-categories/cartofi.webp', $withoutImage->display_image_path);
    }

    public function test_supplier_product_display_image_url_resolves_the_category_image_by_name(): void
    {
        ProductCategory::create(['name' => 'Cartofi', 'image_path' => 'product-categories/cartofi.webp']);

        $withoutImage = new SupplierProduct(['category' => 'Cartofi']);
        $unmatched = new SupplierProduct(['category' => 'Necunoscut']);

        $this->assertStringContainsString('product-categories/cartofi.webp', $withoutImage->displayImageUrl());
        $this->assertNull($unmatched->displayImageUrl());
    }
}
