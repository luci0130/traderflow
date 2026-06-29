<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\MarketComparison\Models\SupplierReview;
use App\Modules\Producers\Models\ProducerOrder;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_reviews_table_is_global_reference_data(): void
    {
        $this->assertTrue(Schema::hasTable('supplier_reviews'));
        $this->assertFalse(
            Schema::hasColumn('supplier_reviews', 'tenant_id'),
            'Supplier reputation is shared and the table must not have a tenant_id column.',
        );
    }

    public function test_a_review_links_a_supplier_and_an_order(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $order = $this->order($supplier);

        $review = SupplierReview::create([
            'supplier_id' => $supplier->id,
            'producer_order_id' => $order->id,
            'rating' => 5,
            'comment' => 'Totul a fost bine',
        ]);

        $this->assertTrue($review->supplier->is($supplier));
        $this->assertTrue($review->producerOrder->is($order));
        $this->assertTrue($order->review->is($review));
        $this->assertTrue($supplier->reviews->first()->is($review));
    }

    public function test_average_rating_is_computed_across_reviews(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);

        SupplierReview::create(['supplier_id' => $supplier->id, 'producer_order_id' => $this->order($supplier)->id, 'rating' => 5]);
        SupplierReview::create(['supplier_id' => $supplier->id, 'producer_order_id' => $this->order($supplier)->id, 'rating' => 2]);

        $this->assertSame(3.5, $supplier->fresh()->average_rating);
    }

    public function test_average_rating_is_null_without_reviews(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);

        $this->assertNull($supplier->average_rating);
    }

    public function test_only_one_review_is_allowed_per_order(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $order = $this->order($supplier);

        SupplierReview::create(['supplier_id' => $supplier->id, 'producer_order_id' => $order->id, 'rating' => 4]);

        $this->expectException(QueryException::class);

        SupplierReview::create(['supplier_id' => $supplier->id, 'producer_order_id' => $order->id, 'rating' => 2]);
    }

    private function order(Supplier $supplier): ProducerOrder
    {
        $tenant = Tenant::create(['name' => 'Tenant '.uniqid()]);

        return ProducerOrder::create([
            'tenant_id' => $tenant->id,
            'producer_id' => $supplier->id,
            'order_date' => today(),
            'status' => 'delivered',
        ]);
    }
}
