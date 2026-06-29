<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Producers\Filament\Resources\ProducerOrders\Pages\ListProducerOrders;
use App\Modules\Producers\Filament\Resources\ProducerOrders\ProducerOrderResource;
use App\Modules\Producers\Models\Producer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class ProducerOrdersVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_producer_sees_only_sales_order_items_tagged_with_their_supplier_products(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Trader', 'is_active' => true, 'currency' => 'EUR']);

        setPermissionsTeamId(null);
        Role::create(['name' => 'producer', 'guard_name' => 'web', 'tenant_id' => null]);

        $producer = Producer::create(['name' => 'Producer A', 'status' => 'active']);
        $producerUser = User::factory()->create(['producer_id' => $producer->id]);
        $producerUser->assignRole('producer');

        $otherProducer = Producer::create(['name' => 'Producer B', 'status' => 'active']);

        $ownProduct = SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Apples',
            'status' => 'active',
            'currency' => 'EUR',
        ]);
        $foreignProduct = SupplierProduct::create([
            'producer_id' => $otherProducer->id,
            'name' => 'Pears',
            'status' => 'active',
            'currency' => 'EUR',
        ]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Buyer']);
        $tenantProduct = Product::create(['tenant_id' => $tenant->id, 'name' => 'Apples cat']);

        $order = SalesOrder::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'order_number' => 'SO-001',
            'order_date' => today(),
            'currency' => 'EUR',
            'status' => 'confirmed',
        ]);

        $ownLine = SalesOrderItem::create([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $order->id,
            'product_id' => $tenantProduct->id,
            'supplier_product_id' => $ownProduct->id,
            'quantity' => 10,
            'sale_price' => 12.5,
        ]);
        $foreignLine = SalesOrderItem::create([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $order->id,
            'product_id' => $tenantProduct->id,
            'supplier_product_id' => $foreignProduct->id,
            'quantity' => 5,
            'sale_price' => 9.0,
        ]);
        $untaggedLine = SalesOrderItem::create([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $order->id,
            'product_id' => $tenantProduct->id,
            'supplier_product_id' => null,
            'quantity' => 3,
            'sale_price' => 7.0,
        ]);

        Filament::setCurrentPanel('producer');
        $this->actingAs($producerUser);

        $records = ProducerOrderResource::getEloquentQuery()->get();
        $ids = $records->pluck('id')->all();

        $this->assertContains($ownLine->id, $ids, 'Producer must see own tagged line.');
        $this->assertNotContains($foreignLine->id, $ids, 'Producer must not see other producer line.');
        $this->assertNotContains($untaggedLine->id, $ids, 'Producer must not see untagged lines.');
    }

    public function test_producer_orders_page_is_read_only(): void
    {
        $this->assertFalse(ProducerOrderResource::canCreate());
        $this->assertFalse(ProducerOrderResource::canEdit(new SalesOrderItem));
        $this->assertFalse(ProducerOrderResource::canDelete(new SalesOrderItem));
    }

    public function test_non_producer_user_cannot_view_any_producer_orders(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->assertFalse(ProducerOrderResource::canViewAny());
    }

    public function test_producer_orders_list_page_renders(): void
    {
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => 'producer', 'guard_name' => 'web', 'tenant_id' => null]);

        $producer = Producer::create(['name' => 'Producer Z', 'status' => 'active']);
        $producerUser = User::factory()->create(['producer_id' => $producer->id]);
        $producerUser->assignRole('producer');

        Filament::setCurrentPanel('producer');
        $this->actingAs($producerUser);

        Livewire::test(ListProducerOrders::class)->assertSuccessful();
    }
}
