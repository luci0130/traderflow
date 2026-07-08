<?php

namespace Tests\Feature;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalProductDisplayImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_the_linked_category_image(): void
    {
        $category = ProductCategory::create(['name' => 'Cartofi', 'image_path' => 'product-categories/cartofi.webp']);
        $product = CanonicalProduct::factory()->create([
            'name' => 'Cartofi albi',
            'product_category_id' => $category->id,
        ]);

        $this->assertStringContainsString('product-categories/cartofi.webp', $product->displayImageUrl());
    }

    public function test_falls_back_to_a_category_name_match_when_not_linked(): void
    {
        ProductCategory::create(['name' => 'Cartofi albi', 'image_path' => 'product-categories/cartofi-albi.webp']);
        $product = CanonicalProduct::factory()->create([
            'name' => 'Cartofi albi',
            'product_category_id' => null,
        ]);

        $this->assertStringContainsString('product-categories/cartofi-albi.webp', $product->displayImageUrl());
    }

    public function test_is_null_when_no_category_image_matches(): void
    {
        $product = CanonicalProduct::factory()->create([
            'name' => 'Ceva necunoscut',
            'product_category_id' => null,
        ]);

        $this->assertNull($product->displayImageUrl());
    }
}
