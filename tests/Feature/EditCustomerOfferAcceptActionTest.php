<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\Pages\EditSupplierOrder;
use App\Modules\SupplierOrders\Filament\Resources\SupplierOrders\SupplierOrderResource;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class EditCustomerOfferAcceptActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_orders_resource_is_registered(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.supplier-orders.index'));
        $this->assertTrue(Route::has('filament.admin.resources.supplier-orders.edit'));
    }

    public function test_supplier_order_edit_page_renders_with_null_offer_numbers(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:SupplierOrder', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'View:SupplierOrder', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:SupplierOrder', 'guard_name' => 'web']),
        );

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Client']);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Ferma']);

        // Offers created by the builder have a null offer_number; the select options
        // must still render (a null label previously crashed the page).
        $customerOffer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'status' => 'accepted', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $supplierOffer = SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'customer_offer_id' => $customerOffer->id,
            'currency' => 'RON', 'status' => 'approved', 'source_type' => 'manual', 'received_at' => today(),
        ]);
        $order = SupplierOrder::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'supplier_offer_id' => $supplierOffer->id,
            'customer_offer_id' => $customerOffer->id, 'currency' => 'RON', 'status' => 'draft',
            'order_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);

        Livewire::test(EditSupplierOrder::class, [
            'record' => $order->getRouteKey(),
        ])->assertSuccessful();
    }

    public function test_accept_offer_action_creates_sales_and_supplier_orders(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'View:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:CustomerOffer', 'guard_name' => 'web']),
        );

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Mega Image']);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Ferma']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Mere']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'status' => 'draft', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $item = CustomerOfferItem::create(['tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $product->id, 'quantity' => 10, 'purchase_price' => 2, 'sale_price' => 3, 'tax_rate' => 0, 'line_total' => 30]);
        $item->suppliers()->create(['supplier_id' => $supplier->id, 'priority' => 1, 'include_in_order' => true, 'landed_cost' => 2, 'currency' => 'RON', 'secured_quantity' => 10]);

        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->callAction('acceptOffer')
            ->assertHasNoActionErrors();

        $this->assertSame('accepted', $offer->refresh()->status);
        $this->assertSame(1, SalesOrder::query()->count());
        $this->assertSame(1, SupplierOrder::query()->count());
        $this->assertDatabaseHas('supplier_orders', [
            'customer_offer_id' => $offer->id,
            'supplier_id' => $supplier->id,
        ]);

        // Once converted, the action hides (a sales order now exists).
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->assertActionHidden('acceptOffer');

        // The created supplier order is editable through its resource.
        $this->assertTrue(Route::has('filament.admin.resources.supplier-orders.edit'));
        $this->assertNotNull(SupplierOrderResource::getUrl('edit', ['record' => SupplierOrder::query()->first()]));
    }
}
