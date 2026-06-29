<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\OrdersRelationManager;
use App\Modules\Producers\Models\Producer;
use App\Modules\Producers\Models\ProducerOrder;
use App\Modules\Producers\Models\ProducerOrderItem;
use App\Modules\Producers\Models\SupplierProduct;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class ProducerOrdersFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private User $superAdmin;

    private Producer $producer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'is_active' => true, 'currency' => 'EUR']);
        $this->otherTenant = Tenant::create(['name' => 'Other', 'is_active' => true, 'currency' => 'EUR']);

        Filament::setCurrentPanel('admin');

        setPermissionsTeamId($this->tenant->getKey());
        $superRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $this->tenant->getKey()]);
        $this->superAdmin = User::factory()->create(['email' => 'super@acme.test']);
        $this->tenant->users()->attach($this->superAdmin, ['role' => 'super_admin']);
        $this->superAdmin->assignRole($superRole);

        $this->producer = Producer::create(['name' => 'Producer A', 'status' => 'active']);
    }

    public function test_relation_is_registered_on_producer_resource(): void
    {
        $this->assertContains(OrdersRelationManager::class, SupplierResource::getRelations());
    }

    public function test_line_total_recomputes_on_save_and_propagates_to_order_total(): void
    {
        $order = ProducerOrder::create([
            'tenant_id' => $this->tenant->id,
            'producer_id' => $this->producer->id,
            'order_number' => 'PO-001',
            'order_date' => today(),
            'currency' => 'EUR',
            'status' => 'draft',
            'created_by' => $this->superAdmin->id,
        ]);

        $product = SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'Apples',
            'status' => 'active',
            'currency' => 'EUR',
            'unit_price' => 5.5,
            'min_quantity_unit' => 'kg',
        ]);

        ProducerOrderItem::create([
            'producer_order_id' => $order->id,
            'supplier_product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 10,
            'unit' => 'kg',
            'unit_price' => 5.5,
            'currency' => 'EUR',
        ]);

        ProducerOrderItem::create([
            'producer_order_id' => $order->id,
            'supplier_product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 4,
            'unit' => 'kg',
            'unit_price' => 5,
            'currency' => 'EUR',
        ]);

        $order->refresh();

        $this->assertEquals(55, $order->items()->where('quantity', 10)->value('line_total'));
        $this->assertEquals(20, $order->items()->where('quantity', 4)->value('line_total'));
        $this->assertEquals(75, (float) $order->total);
    }

    public function test_relation_lists_only_current_tenants_orders_for_this_producer(): void
    {
        $own = ProducerOrder::create([
            'tenant_id' => $this->tenant->id,
            'producer_id' => $this->producer->id,
            'order_number' => 'PO-OWN',
            'currency' => 'EUR',
            'status' => 'draft',
        ]);

        $otherTenantOrder = ProducerOrder::create([
            'tenant_id' => $this->otherTenant->id,
            'producer_id' => $this->producer->id,
            'order_number' => 'PO-OTHER',
            'currency' => 'EUR',
            'status' => 'draft',
        ]);

        $otherProducer = Producer::create(['name' => 'Other producer', 'status' => 'active']);
        $foreignProducerOrder = ProducerOrder::create([
            'tenant_id' => $this->tenant->id,
            'producer_id' => $otherProducer->id,
            'order_number' => 'PO-FOREIGN',
            'currency' => 'EUR',
            'status' => 'draft',
        ]);

        $this->actingAs($this->superAdmin);
        session(['tenant_id' => $this->tenant->id]);

        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $this->producer,
            'pageClass' => SupplierResource\Pages\EditSupplier::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$otherTenantOrder, $foreignProducerOrder]);
    }

    public function test_badge_reflects_order_count(): void
    {
        ProducerOrder::create([
            'tenant_id' => $this->tenant->id,
            'producer_id' => $this->producer->id,
            'order_number' => 'PO-1',
            'currency' => 'EUR',
            'status' => 'draft',
        ]);
        ProducerOrder::create([
            'tenant_id' => $this->tenant->id,
            'producer_id' => $this->producer->id,
            'order_number' => 'PO-2',
            'currency' => 'EUR',
            'status' => 'sent',
        ]);

        $this->actingAs($this->superAdmin);
        $this->assertSame('2', OrdersRelationManager::getBadge($this->producer->fresh(), SupplierResource\Pages\EditSupplier::class));
    }
}
