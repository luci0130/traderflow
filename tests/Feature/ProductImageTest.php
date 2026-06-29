<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_table_has_an_image_path_column(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'image_path'));
    }

    public function test_deleting_a_product_removes_its_image_from_the_public_disk(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);

        $path = 'products/'.$tenant->id.'/tomato.jpg';
        Storage::disk('public')->put($path, 'binary');

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tomato',
            'image_path' => $path,
        ]);

        Storage::disk('public')->assertExists($path);

        $product->delete();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_a_product_without_an_image_does_not_error(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cucumber',
        ]);

        $product->delete();

        $this->assertModelMissing($product);
    }
}
