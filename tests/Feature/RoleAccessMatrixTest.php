<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $permissionNames = [
        'ViewAny:Customer', 'Create:Customer',
        'ViewAny:Supplier', 'Create:Supplier',
        'ViewAny:SupermarketPrice', 'Create:SupermarketPrice',
        'ViewAny:SupermarketProduct',
        'ViewAny:Transporter',
        'ViewAny:SupplierProduct', 'View:SupplierProduct', 'Create:SupplierProduct', 'Update:SupplierProduct', 'Delete:SupplierProduct',
        'ViewAny:SupplierOrder', 'Create:SupplierOrder',
        'ViewAny:Product', 'View:Product', 'Create:Product',
        'ViewAny:CanonicalProduct', 'Create:CanonicalProduct',
        'View:BestPrices', 'View:MarketComparison',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->permissionNames as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(string $role): array
    {
        return Role::query()
            ->where('name', $role)
            ->whereNull('tenant_id')
            ->firstOrFail()
            ->permissions
            ->pluck('name')
            ->all();
    }

    public function test_the_application_exposes_exactly_three_global_roles(): void
    {
        $this->assertSame(
            ['producer', 'purchasing_agent', 'sales_agent', 'super_admin'],
            Role::query()->whereNull('tenant_id')->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_super_admin_holds_every_permission(): void
    {
        $permissions = $this->permissionsFor('super_admin');

        foreach ($this->permissionNames as $name) {
            $this->assertContains($name, $permissions);
        }
    }

    public function test_sales_agent_sees_customers_and_supermarkets_but_not_suppliers(): void
    {
        $permissions = $this->permissionsFor('sales_agent');

        $this->assertContains('ViewAny:Customer', $permissions);
        $this->assertContains('Create:Customer', $permissions);
        $this->assertContains('ViewAny:SupermarketPrice', $permissions);
        $this->assertContains('ViewAny:SupermarketProduct', $permissions);
        $this->assertContains('View:BestPrices', $permissions);
        $this->assertContains('View:MarketComparison', $permissions);

        $this->assertNotContains('ViewAny:Supplier', $permissions);
        $this->assertNotContains('ViewAny:Transporter', $permissions);
        $this->assertNotContains('ViewAny:SupplierProduct', $permissions);
    }

    public function test_purchasing_agent_sees_suppliers_and_transporters_but_not_customers_or_supermarkets(): void
    {
        $permissions = $this->permissionsFor('purchasing_agent');

        $this->assertContains('ViewAny:Supplier', $permissions);
        $this->assertContains('Create:Supplier', $permissions);
        $this->assertContains('ViewAny:Transporter', $permissions);
        $this->assertContains('ViewAny:SupplierProduct', $permissions);
        $this->assertContains('ViewAny:SupplierOrder', $permissions);
        $this->assertContains('Create:SupplierOrder', $permissions);

        $this->assertNotContains('ViewAny:Customer', $permissions);
        $this->assertNotContains('ViewAny:SupermarketPrice', $permissions);
        $this->assertNotContains('ViewAny:SupermarketProduct', $permissions);
        $this->assertNotContains('View:BestPrices', $permissions);
    }

    public function test_purchasing_agent_views_canonical_products_but_not_the_product_catalog(): void
    {
        $permissions = $this->permissionsFor('purchasing_agent');

        // Canonical products stay (read only); the product catalog is removed.
        $this->assertContains('ViewAny:CanonicalProduct', $permissions);
        $this->assertNotContains('Create:CanonicalProduct', $permissions);

        $this->assertNotContains('ViewAny:Product', $permissions, 'purchasing_agent must not access the product catalog');
        $this->assertNotContains('View:Product', $permissions);
    }

    public function test_sales_agent_has_no_catalog_access(): void
    {
        $permissions = $this->permissionsFor('sales_agent');

        $this->assertNotContains('ViewAny:Product', $permissions, 'sales_agent must not access the catalog');
        $this->assertNotContains('View:Product', $permissions);
        $this->assertNotContains('ViewAny:CanonicalProduct', $permissions);
    }
}
