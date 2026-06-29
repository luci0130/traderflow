<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Reports\Filament\Pages\ProductProfitReport;
use App\Modules\Reports\Filament\Pages\SalesOrderProfitReport;
use App\Modules\Reports\Filament\Pages\SupermarketMarginReport;
use App\Modules\Reports\Filament\Pages\SupermarketProductMarginReport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class SalesAccessRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Subjects across the access matrix, each expanded into CRUD permissions so
     * the seeder has a realistic set to sync against.
     */
    private function seedPermissions(): void
    {
        $subjects = [
            'Customer', 'CustomerOffer', 'SalesOrder', 'SupermarketPrice', 'SupermarketProduct',
            'Supplier', 'SupplierOffer', 'SupplierProduct', 'SupplierOrder', 'SupplierLead', 'Transporter',
            'Product', 'ProductCategory', 'Unit', 'PackagingMethod', 'CanonicalProduct',
        ];

        foreach ($subjects as $subject) {
            foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $verb) {
                Permission::create(['name' => "{$verb}:{$subject}", 'guard_name' => 'web']);
            }
        }
    }

    private function salesRolePermissionNames(): \Illuminate\Support\Collection
    {
        setPermissionsTeamId(null);

        return Role::where('name', 'sales_agent')
            ->whereNull('tenant_id')
            ->firstOrFail()
            ->permissions
            ->pluck('name');
    }

    public function test_seeder_grants_sales_no_catalog_or_supplier_lead_access(): void
    {
        $this->seedPermissions();
        $this->seed(DatabaseSeeder::class);

        $names = $this->salesRolePermissionNames();

        // Core sales work is retained.
        $this->assertTrue($names->contains('ViewAny:Customer'));
        $this->assertTrue($names->contains('Create:CustomerOffer'));
        $this->assertTrue($names->contains('ViewAny:SalesOrder'));

        // Catalog, canonical products and supplier leads are removed.
        foreach ([
            'ViewAny:Product', 'ViewAny:ProductCategory', 'ViewAny:Unit',
            'ViewAny:PackagingMethod', 'ViewAny:CanonicalProduct',
            'ViewAny:SupplierLead', 'Create:SupplierLead',
        ] as $forbidden) {
            $this->assertFalse($names->contains($forbidden), "sales_agent should not have {$forbidden}");
        }
    }

    public function test_seeder_configures_purchasing_catalog_and_supplier_orders(): void
    {
        $this->seedPermissions();
        $this->seed(DatabaseSeeder::class);

        setPermissionsTeamId(null);
        $names = Role::where('name', 'purchasing_agent')->whereNull('tenant_id')->firstOrFail()->permissions->pluck('name');

        // Canonical products, supplier leads and supplier orders are available.
        $this->assertTrue($names->contains('ViewAny:CanonicalProduct'));
        $this->assertTrue($names->contains('ViewAny:SupplierLead'));
        $this->assertTrue($names->contains('ViewAny:SupplierOrder'));
        $this->assertTrue($names->contains('Create:SupplierOrder'));

        // The product catalog is no longer accessible to purchasing.
        foreach (['ViewAny:Product', 'ViewAny:ProductCategory', 'ViewAny:Unit', 'ViewAny:PackagingMethod'] as $forbidden) {
            $this->assertFalse($names->contains($forbidden), "purchasing_agent should not have {$forbidden}");
        }
    }

    public function test_sales_agent_cannot_access_report_pages(): void
    {
        setPermissionsTeamId(null);
        Role::create(['name' => 'sales_agent', 'guard_name' => 'web', 'tenant_id' => null]);

        $sales = User::factory()->create();
        $sales->assignRole('sales_agent');

        $this->actingAs($sales->refresh());

        $this->assertFalse(SalesOrderProfitReport::canAccess());
        $this->assertFalse(ProductProfitReport::canAccess());
        $this->assertFalse(SupermarketMarginReport::canAccess());
        $this->assertFalse(SupermarketProductMarginReport::canAccess());
    }

    public function test_super_admin_can_access_report_pages(): void
    {
        setPermissionsTeamId(null);
        Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin->refresh());

        $this->assertTrue(SalesOrderProfitReport::canAccess());
        $this->assertTrue(ProductProfitReport::canAccess());
        $this->assertTrue(SupermarketMarginReport::canAccess());
        $this->assertTrue(SupermarketProductMarginReport::canAccess());
    }
}
