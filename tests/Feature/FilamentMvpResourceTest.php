<?php

namespace Tests\Feature;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\SupplierOfferResource;
use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Units\Filament\Resources\Units\UnitResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class FilamentMvpResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_requested_resources_are_registered_as_filament_routes(): void
    {
        $resources = [
            TenantResource::class => 'filament.admin.resources.tenants.index',
            ProductCategoryResource::class => 'filament.admin.resources.product-categories.index',
            UnitResource::class => 'filament.admin.resources.units.index',
            ProductResource::class => 'filament.admin.resources.products.index',
            SupplierResource::class => 'filament.admin.resources.suppliers.index',
            CustomerResource::class => 'filament.admin.resources.customers.index',
            SupplierOfferResource::class => 'filament.admin.resources.supplier-offers.index',
            CustomerOfferResource::class => 'filament.admin.resources.customer-offers.index',
            SalesOrderResource::class => 'filament.admin.resources.sales-orders.index',
        ];

        foreach ($resources as $resource => $routeName) {
            $this->assertTrue(class_exists($resource));
            $this->assertTrue(Route::has($routeName), "Route [{$routeName}] is not registered.");
        }

        $this->assertNotEmpty(SupplierOfferResource::getRelations());
        $this->assertNotEmpty(CustomerOfferResource::getRelations());
        $this->assertNotEmpty(SalesOrderResource::getRelations());
    }

    public function test_catalog_is_global_while_documents_stay_scoped_to_the_active_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);
        $user = User::factory()->create();

        // Shared global catalog (tenant_id = null) is visible regardless of tenant.
        Product::create(['tenant_id' => null, 'name' => 'Rosii']);
        Product::create(['tenant_id' => null, 'name' => 'Ardei']);

        // Documents belong to a specific tenant.
        $customerA = Customer::create(['name' => 'Client A', 'tenant_id' => $tenantA->id]);
        $customerB = Customer::create(['name' => 'Client B', 'tenant_id' => $tenantB->id]);
        CustomerOffer::create(['tenant_id' => $tenantA->id, 'customer_id' => $customerA->id, 'offer_number' => 'A-1', 'offer_date' => now(), 'currency' => 'RON', 'status' => 'draft']);
        CustomerOffer::create(['tenant_id' => $tenantB->id, 'customer_id' => $customerB->id, 'offer_number' => 'B-1', 'offer_date' => now(), 'currency' => 'RON', 'status' => 'draft']);

        $this->actingAs($user);
        session(['tenant_id' => $tenantA->id]);

        // Catalog: global → both products visible regardless of the active tenant.
        $this->assertSame(2, ProductResource::getEloquentQuery()->count());

        // Documents: scoped to the active tenant.
        $this->assertSame(1, CustomerOfferResource::getEloquentQuery()->count());
    }
}
