<?php

namespace Tests\Feature;

use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Filament\RelationManagers\LocationsRelationManager;
use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Filament\Resources\Customers\Pages\ListCustomers;
use App\Modules\Customers\Models\CustomerLocation;
use App\Modules\Dashboard\Filament\Widgets\SupplierLeadsToConvertWidget;
use App\Modules\MarketComparison\Filament\Pages\MarketComparison;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages\ListCanonicalProducts;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Supermarkets\Filament\RelationManagers\SupermarketPricesRelationManager;
use App\Modules\Supermarkets\Filament\RelationManagers\SupermarketProductsRelationManager;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages\ListSupermarketPrices;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages\ListSupermarketProducts;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\EditSupplierLead;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\ListSupplierLeads;
use App\Modules\Suppliers\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Database\Factories\SupermarketFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CountryFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        setPermissionsTeamId(null);
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]);
        $user->assignRole($role);
        $user->assignRole(Role::create(['name' => 'purchasing_agent', 'guard_name' => 'web', 'tenant_id' => null]));
        setPermissionsTeamId($tenant->id);
        $this->actingAs($user);
        session(['tenant_id' => $tenant->id]);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($tenant);
    }

    public function test_lead_country_filter_groups_country_codes_and_names_and_ignores_empty_values(): void
    {
        $code = SupplierLead::factory()->create(['country' => 'RO']);
        $name = SupplierLead::factory()->create(['country' => 'România']);
        $other = SupplierLead::factory()->create(['country' => 'ES']);
        $empty = SupplierLead::factory()->create(['country' => null]);

        $page = Livewire::test(ListSupplierLeads::class);
        $options = $page->instance()->getTable()->getFilter('country')->getOptions();
        $this->assertCount(2, $options);
        $this->assertArrayHasKey('RO', $options);

        $page->filterTable('country', 'RO')->assertCanSeeTableRecords([$code, $name])
            ->assertCanNotSeeTableRecords([$other, $empty]);
    }

    public function test_supplier_and_customer_grids_filter_by_country(): void
    {
        $supplier = Supplier::create(['name' => 'Romanian supplier', 'country' => 'RO']);
        $otherSupplier = Supplier::create(['name' => 'Spanish supplier', 'country' => 'ES']);
        Livewire::test(ListSuppliers::class)->filterTable('country', 'RO')
            ->assertCanSeeTableRecords([$supplier])->assertCanNotSeeTableRecords([$otherSupplier]);

        $customer = SupermarketFactory::new()->create(['country' => 'RO']);
        $otherCustomer = SupermarketFactory::new()->create(['country' => 'ES']);
        Livewire::test(ListCustomers::class)->filterTable('country', 'RO')
            ->assertCanSeeTableRecords([$customer])->assertCanNotSeeTableRecords([$otherCustomer]);
    }

    public function test_supermarket_and_canonical_product_grids_filter_by_origin(): void
    {
        $product = SupermarketProduct::factory()->create(['origin' => 'RO']);
        $other = SupermarketProduct::factory()->create(['origin' => 'ES']);
        Livewire::test(ListSupermarketProducts::class)->filterTable('origin', 'RO')
            ->assertCanSeeTableRecords([$product])->assertCanNotSeeTableRecords([$other]);

        $canonical = CanonicalProduct::factory()->create(['country_of_origin' => 'RO']);
        $otherCanonical = CanonicalProduct::factory()->create(['country_of_origin' => 'ES']);
        foreach ([ListCanonicalProducts::class, MarketComparison::class] as $page) {
            Livewire::test($page)->filterTable('country_of_origin', 'RO')
                ->assertCanSeeTableRecords([$canonical])->assertCanNotSeeTableRecords([$otherCanonical]);
        }
    }

    public function test_tenant_and_dashboard_grids_filter_by_country(): void
    {
        $tenant = Tenant::create(['name' => 'Romanian tenant', 'country' => 'RO']);
        $other = Tenant::create(['name' => 'Spanish tenant', 'country' => 'ES']);
        Livewire::test(ListTenants::class)->filterTable('country', 'RO')
            ->assertCanSeeTableRecords([$tenant])->assertCanNotSeeTableRecords([$other]);

        $lead = SupplierLead::factory()->create(['country' => 'RO']);
        $otherLead = SupplierLead::factory()->create(['country' => 'ES']);
        Livewire::withoutLazyLoading()->test(SupplierLeadsToConvertWidget::class)->filterTable('country', 'RO')
            ->assertCanSeeTableRecords([$lead])->assertCanNotSeeTableRecords([$otherLead]);
    }

    public function test_location_country_options_and_results_remain_scoped_to_the_customer(): void
    {
        $customer = SupermarketFactory::new()->create();
        $otherCustomer = SupermarketFactory::new()->create();
        $location = CustomerLocation::factory()->create(['customer_id' => $customer->id, 'country' => 'RO']);
        $other = CustomerLocation::factory()->create(['customer_id' => $customer->id, 'country' => 'ES']);
        CustomerLocation::factory()->create(['customer_id' => $otherCustomer->id, 'country' => 'FR']);

        $page = Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class]);
        $this->assertArrayNotHasKey('FR', $page->instance()->getTable()->getFilter('country')->getOptions());
        $page->filterTable('country', 'RO')->assertCanSeeTableRecords([$location])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_associated_product_country_filter_works_with_the_pivot_join(): void
    {
        $lead = SupplierLead::factory()->create();
        $product = SupermarketProduct::factory()->create(['origin' => 'RO']);
        $other = SupermarketProduct::factory()->create(['origin' => 'ES']);
        $lead->supermarketProducts()->attach([$product->id, $other->id]);

        Livewire::test(SupermarketProductsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditSupplierLead::class])
            ->filterTable('origin', 'RO')->assertCanSeeTableRecords([$product])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_price_country_filters_match_all_products_from_the_country_and_scope_history_options(): void
    {
        $lead = SupplierLead::factory()->create();
        $romanian = SupermarketProduct::factory()->count(2)->create(['origin' => 'RO']);
        $prices = $romanian->map(fn (SupermarketProduct $product): SupermarketPrice => SupermarketPrice::factory()->create([
            'supplier_lead_id' => $lead->id, 'supermarket_product_id' => $product->id,
        ]));
        $spanish = SupermarketPrice::factory()->create([
            'supplier_lead_id' => $lead->id,
            'supermarket_product_id' => SupermarketProduct::factory()->create(['origin' => 'ES'])->id,
        ]);
        SupermarketPrice::factory()->create([
            'supermarket_product_id' => SupermarketProduct::factory()->create(['origin' => 'FR'])->id,
        ]);

        Livewire::test(ListSupermarketPrices::class)->filterTable('product_origin', 'RO')
            ->assertCanSeeTableRecords($prices)->assertCanNotSeeTableRecords([$spanish]);

        $page = Livewire::test(SupermarketPricesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditSupplierLead::class]);
        $this->assertArrayNotHasKey('FR', $page->instance()->getTable()->getFilter('product_origin')->getOptions());
        $page->filterTable('product_origin', 'RO')->assertCanSeeTableRecords($prices)->assertCanNotSeeTableRecords([$spanish]);
    }
}
