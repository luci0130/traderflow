<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\CreateSupplierProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\EditSupplierProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class AdminSupplierProductResourceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'is_active' => true, 'currency' => 'EUR']);

        Filament::setCurrentPanel('admin');

        setPermissionsTeamId($this->tenant->getKey());
        $superRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $this->tenant->getKey()]);

        $this->superAdmin = User::factory()->create();
        $this->tenant->users()->attach($this->superAdmin, ['role' => 'super_admin']);
        $this->superAdmin->assignRole($superRole);

        session(['tenant_id' => $this->tenant->getKey()]);

        $this->actingAs($this->superAdmin);
    }

    public function test_resource_is_registered_in_admin_catalog(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.supplier-products.index'));
        $this->assertSame('Catalog', SupplierProductResource::getNavigationGroup());
    }

    public function test_list_shows_products_from_every_supplier(): void
    {
        // Regular tenant suppliers (is_producer = 0) — the real-world case.
        $verde = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $agricola = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Agricola']);

        $first = SupplierProduct::create(['producer_id' => $verde->id, 'name' => 'Rosii Verde', 'status' => 'active', 'currency' => 'EUR']);
        $second = SupplierProduct::create(['producer_id' => $agricola->id, 'name' => 'Rosii Agricola', 'status' => 'active', 'currency' => 'EUR']);

        Livewire::test(ListSupplierProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$first, $second])
            ->assertSee('Ferma Verde')
            ->assertSee('Agricola');
    }

    public function test_create_persists_product_with_tiers_and_cost_override(): void
    {
        $producer = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $packaging = PackagingMethod::query()->where('name', 'Plasă')->firstOrFail();

        Livewire::test(CreateSupplierProduct::class)
            ->fillForm([
                'producer_id' => $producer->id,
                'name' => 'Castraveti',
                'packaging_method_id' => $packaging->id,
                'status' => 'active',
                'is_bio' => true,
                'currency' => 'RON',
                'min_quantity_unit' => 'kg',
                'min_quantity_value' => '50',
                'unit_price' => '5.20',
                'prices' => [
                    ['min_quantity_value' => '50', 'unit_price' => '5.20'],
                    ['min_quantity_value' => '200', 'unit_price' => '4.80'],
                ],
                'cost_override' => [
                    'packaging_cost' => '0.30',
                    'transport_cost' => '0.50',
                    'commission' => '0.10',
                    'profit_margin' => '0.40',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = SupplierProduct::query()->where('name', 'Castraveti')->firstOrFail();

        $this->assertSame($producer->id, $product->producer_id);
        $this->assertSame($packaging->id, $product->packaging_method_id);
        $this->assertTrue($product->is_bio);
        // First tier mirrors onto the default price columns.
        $this->assertSame('5.2000', $product->unit_price);
        $this->assertSame('50.0000', $product->min_quantity_value);
        $this->assertSame(
            [50, 200],
            $product->prices()->orderBy('sort_order')->pluck('min_quantity_value')->map(fn (string $v): int => (int) $v)->all(),
        );

        $this->assertDatabaseHas('supplier_product_cost_overrides', [
            'supplier_product_id' => $product->id,
            'packaging_cost' => 0.3,
            'transport_cost' => 0.5,
            'commission' => 0.1,
            'profit_margin' => 0.4,
        ]);
    }

    public function test_type_column_is_dropped_in_favour_of_quality(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('supplier_products', 'type'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('supplier_products', 'quality'));
    }

    public function test_create_persists_the_quality_advanced_field(): void
    {
        $producer = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);

        Livewire::test(CreateSupplierProduct::class)
            ->fillForm([
                'producer_id' => $producer->id,
                'name' => 'Mere',
                'status' => 'active',
                'currency' => 'RON',
                'min_quantity_unit' => 'kg',
                'min_quantity_value' => '50',
                'unit_price' => '3.10',
                'quality' => 'Class I',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Class I', SupplierProduct::query()->where('name', 'Mere')->value('quality'));
    }

    public function test_create_persists_structured_packaging_size_and_unit(): void
    {
        $producer = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $packaging = PackagingMethod::query()->where('name', 'Plasă')->firstOrFail();

        Livewire::test(CreateSupplierProduct::class)
            ->fillForm([
                'producer_id' => $producer->id,
                'name' => 'Cartofi',
                'packaging_method_id' => $packaging->id,
                'package_size' => '10',
                'status' => 'active',
                'currency' => 'RON',
                'min_quantity_unit' => 'kg',
                'min_quantity_value' => '100',
                'unit_price' => '2.50',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = SupplierProduct::query()->where('name', 'Cartofi')->firstOrFail();

        $this->assertSame($packaging->id, $product->packaging_method_id);
        $this->assertSame('10.0000', $product->package_size);
        $this->assertSame('kg', $product->min_quantity_unit);
    }

    public function test_category_is_selected_from_the_product_category_taxonomy(): void
    {
        $producer = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        ProductCategory::create(['name' => 'Legume']);
        // A legacy free-text value already on a product stays selectable.
        SupplierProduct::create(['producer_id' => $producer->id, 'name' => 'Veche', 'status' => 'active', 'currency' => 'EUR', 'category' => 'Broccoli']);

        $options = SupplierProductResource::categoryOptions();
        $this->assertArrayHasKey('Legume', $options);
        $this->assertArrayHasKey('Broccoli', $options);

        Livewire::test(CreateSupplierProduct::class)
            ->fillForm([
                'producer_id' => $producer->id,
                'name' => 'Morcovi',
                'category' => 'Legume',
                'status' => 'active',
                'currency' => 'RON',
                'min_quantity_unit' => 'kg',
                'min_quantity_value' => '10',
                'unit_price' => '3',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Legume', SupplierProduct::query()->where('name', 'Morcovi')->value('category'));
    }

    public function test_edit_clears_cost_override_when_all_fields_blanked(): void
    {
        $producer = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $product = SupplierProduct::create([
            'producer_id' => $producer->id,
            'name' => 'Ardei',
            'status' => 'active',
            'currency' => 'EUR',
            'min_quantity_unit' => 'kg',
            'min_quantity_value' => 10,
            'unit_price' => 3,
        ]);
        $product->costOverride()->create(['packaging_cost' => 1.0]);

        Livewire::test(EditSupplierProduct::class, ['record' => $product->getRouteKey()])
            ->assertSuccessful()
            ->fillForm([
                'cost_override' => [
                    'packaging_cost' => null,
                    'transport_cost' => null,
                    'commission' => null,
                    'profit_margin' => null,
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($product->fresh()->costOverride);
    }

    public function test_bulk_action_maps_selected_products_to_an_existing_canonical_and_moves_grouped_ones(): void
    {
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $target = CanonicalProduct::create(['name' => 'Ardei kapia']);
        $other = CanonicalProduct::create(['name' => 'Other']);

        $a = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'Ardei kapia', 'status' => 'active', 'currency' => 'RON']);
        $b = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'Ardei kapia rosu', 'status' => 'active', 'currency' => 'RON']);
        $other->supplierProducts()->attach($b->id);

        Livewire::test(ListSupplierProducts::class)
            ->callTableBulkAction('mapToCanonical', [$a->id, $b->id], ['canonical_product_id' => $target->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $target->fresh()->supplierProducts->pluck('id')->all());
        $this->assertCount(0, $other->fresh()->supplierProducts);
    }

    public function test_bulk_action_can_create_a_new_canonical_for_selected_products(): void
    {
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $a = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'Vinete', 'status' => 'active', 'currency' => 'RON']);

        $canonical = CanonicalProduct::create(['name' => 'Vinete', 'package_unit' => 'kg']);

        Livewire::test(ListSupplierProducts::class)
            ->callTableBulkAction('mapToCanonical', [$a->id], ['canonical_product_id' => $canonical->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertTrue($canonical->fresh()->supplierProducts->contains($a->id));
    }
}
