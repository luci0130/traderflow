<?php

namespace Tests\Feature;

use App\Modules\Producers\Models\Producer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProducerOffersStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_none_when_producer_has_no_products(): void
    {
        $producer = Producer::create(['name' => 'Empty', 'status' => 'active']);

        $this->assertSame(Producer::OFFERS_STATUS_NONE, $producer->offers_status);
    }

    public function test_status_is_valid_when_all_products_have_active_offers(): void
    {
        $producer = Producer::create(['name' => 'All valid', 'status' => 'active']);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Apples',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(10),
        ]);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Pears',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(5),
        ]);

        $this->assertSame(Producer::OFFERS_STATUS_VALID, $producer->fresh()->offers_status);
    }

    public function test_status_is_mixed_when_some_valid_and_some_expired(): void
    {
        $producer = Producer::create(['name' => 'Mixed', 'status' => 'active']);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Valid one',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(10),
        ]);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Expired one',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->subDay(),
        ]);

        $this->assertSame(Producer::OFFERS_STATUS_MIXED, $producer->fresh()->offers_status);
    }

    public function test_status_is_expired_when_all_offers_are_expired_or_archived(): void
    {
        $producer = Producer::create(['name' => 'Expired', 'status' => 'active']);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Past',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->subDay(),
        ]);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Archived',
            'status' => 'archived',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(10),
        ]);

        $this->assertSame(Producer::OFFERS_STATUS_EXPIRED, $producer->fresh()->offers_status);
    }

    public function test_resource_query_preloads_supplier_products_counts(): void
    {
        $producer = Producer::create(['name' => 'Query Test', 'status' => 'active']);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Valid',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(5),
        ]);
        SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Expired',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->subDay(),
        ]);

        $loaded = SupplierResource::getEloquentQuery()->find($producer->id);

        $this->assertSame(2, (int) $loaded->supplier_products_count);
        $this->assertSame(1, (int) $loaded->valid_supplier_products_count);
        $this->assertSame(Producer::OFFERS_STATUS_MIXED, $loaded->offers_status);
    }
}
