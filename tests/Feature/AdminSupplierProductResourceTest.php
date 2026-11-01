<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Suppliers\Filament\Pages\SupplierProductsV2;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\CreateSupplierProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\EditSupplierProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function test_supplier_products_v2_loads_latest_week_and_other_weeks_on_demand(): void
    {
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $otherSupplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Alt furnizor']);
        $emptySupplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Fără produse']);
        $latest = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Roșii noi',
            'status' => 'active',
            'currency' => 'EUR',
            'unit_price' => 4,
            'created_at' => '2026-10-05 10:00:00',
        ]);
        $older = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Mere vechi',
            'status' => 'active',
            'currency' => 'EUR',
            'created_at' => '2026-09-28 10:00:00',
        ]);
        SupplierProduct::create([
            'producer_id' => $otherSupplier->id,
            'name' => 'Produs alt furnizor',
            'status' => 'active',
            'currency' => 'EUR',
        ]);

        $this->assertTrue(Route::has('filament.admin.pages.supplier-products-v2'));

        $page = Livewire::test(SupplierProductsV2::class)
            ->assertSuccessful()
            ->assertSee('data-supplier-products-grouped', escape: false)
            ->assertSee('data-supplier-group="'.$supplier->id.'"', escape: false)
            ->assertSee('data-supplier-group="'.$otherSupplier->id.'"', escape: false)
            ->assertDontSee('data-supplier-group="'.$emptySupplier->id.'"', escape: false)
            ->assertSee('await this.$wire.loadSupplier', escape: false)
            ->assertDontSee('Roșii noi')
            ->call('loadSupplier', $supplier->id)
            ->assertSee('Week 41')
            ->assertSee('Week 40')
            ->assertSee('await this.$wire.loadWeek', escape: false)
            ->assertSee('Roșii noi')
            ->assertDontSee('Mere vechi');

        $this->assertSame([$latest->id], $page->instance()->getTableRecords()->modelKeys());

        $page->call('loadWeek', $supplier->id, '2026-09-28')
            ->assertSee('Mere vechi');

        $this->assertEqualsCanonicalizing(
            [$latest->id, $older->id],
            $page->instance()->getTableRecords()->modelKeys(),
        );

        $page->call('loadSupplier', $supplier->id)
            ->call('loadWeek', $supplier->id, '2026-09-28');

        $this->assertSame(['2026-10-05', '2026-09-28'], $page->instance()->loadedWeeks[$supplier->id]);
    }

    public function test_supplier_products_v2_loads_complete_groups_without_pagination(): void
    {
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $otherSupplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Alt furnizor']);
        $productsByGroup = [];

        foreach ([
            [$supplier, '2026-10-05'],
            [$supplier, '2026-09-28'],
            [$otherSupplier, '2026-10-05'],
        ] as $groupIndex => [$groupSupplier, $createdAt]) {
            foreach (range(1, 12) as $productIndex) {
                $productsByGroup[$groupIndex][] = SupplierProduct::create([
                    'producer_id' => $groupSupplier->id,
                    'name' => sprintf('Product %d-%02d', $groupIndex, $productIndex),
                    'status' => 'active',
                    'currency' => 'EUR',
                    'created_at' => $createdAt,
                ]);
            }
        }

        $page = Livewire::test(SupplierProductsV2::class)
            ->set('tableRecordsPerPage', 10)
            ->assertCountTableRecords(0)
            ->call('loadSupplier', $supplier->id)
            ->assertCanSeeTableRecords($productsByGroup[0])
            ->assertCanNotSeeTableRecords([...$productsByGroup[1], ...$productsByGroup[2]]);

        $this->assertCount(12, $page->instance()->getTableRecords());

        $page->call('loadWeek', $supplier->id, '2026-09-28')
            ->assertCanSeeTableRecords([...$productsByGroup[0], ...$productsByGroup[1]])
            ->assertCanNotSeeTableRecords($productsByGroup[2]);

        $this->assertCount(24, $page->instance()->getTableRecords());

        $page->call('loadSupplier', $otherSupplier->id)
            ->assertCanSeeTableRecords([...$productsByGroup[0], ...$productsByGroup[1], ...$productsByGroup[2]])
            ->assertDontSee('class="fi-pagination', escape: false);

        $this->assertFalse($page->instance()->getTable()->isPaginated());
        $this->assertCount(36, $page->instance()->getTableRecords());
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

    public function test_list_groups_by_supplier_before_grouping_by_creation_week(): void
    {
        app()->setLocale('ro');

        $firstSupplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'A Go Fruit']);
        $secondSupplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Z Fruit Active']);
        $records = [];

        foreach ([
            [$secondSupplier, '2026-09-28 10:00:00', 'Mere Active'],
            [$firstSupplier, '2026-09-28 10:00:00', 'Ardei Go Fruit'],
            [$firstSupplier, '2026-09-21 10:00:00', 'Mere Go Fruit'],
            [$firstSupplier, '2026-09-30 10:00:00', 'Rosii Go Fruit'],
            [$secondSupplier, '2026-09-21 10:00:00', 'Ardei Active'],
        ] as [$supplier, $createdAt, $name]) {
            $records[$name] = SupplierProduct::create([
                'producer_id' => $supplier->id,
                'name' => $name,
                'status' => 'active',
                'currency' => 'EUR',
                'created_at' => $createdAt,
            ]);
        }

        $test = Livewire::test(ListSupplierProducts::class)->assertSuccessful()
            ->assertSee('Săptămâna 39')
            ->assertSee('Săptămâna 40')
            ->assertSee('21.09.2026 – 27.09.2026')
            ->assertSee('28.09.2026 – 04.10.2026');

        $this->assertSame([
            $records['Mere Go Fruit']->id,
            $records['Ardei Go Fruit']->id,
            $records['Rosii Go Fruit']->id,
            $records['Ardei Active']->id,
            $records['Mere Active']->id,
        ], $test->instance()->getFilteredSortedTableQuery()->pluck('supplier_products.id')->all());

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$test->html());
        $xpath = new \DOMXPath($document);
        $rows = $xpath->query('//div[@data-supplier-products-grouped]//tbody/tr');
        $types = [];

        foreach ($rows as $row) {
            $types[] = match (true) {
                $row->hasAttribute('data-supplier-group') => 'supplier',
                $row->hasAttribute('data-supplier-week') => 'week',
                default => 'product',
            };
        }

        $this->assertSame(['supplier', 'week', 'product', 'week', 'product', 'product', 'supplier', 'week', 'product', 'week', 'product'], $types);
        $this->assertSame(['supplier.name', 'name', 'packagingMethod.name', 'unit_price', 'currency', 'min_quantity_value'], array_keys($test->instance()->getTable()->getVisibleColumns()));

        $test->set('tableGrouping', 'created_week:desc')->assertSuccessful();
        $this->assertSame([
            $records['Ardei Go Fruit']->id,
            $records['Rosii Go Fruit']->id,
            $records['Mere Go Fruit']->id,
            $records['Mere Active']->id,
            $records['Ardei Active']->id,
        ], $test->instance()->getFilteredSortedTableQuery()->pluck('supplier_products.id')->all());

        $test->set('tableGrouping', null)
            ->assertDontSee('data-supplier-products-grouped', escape: false)
            ->assertCanSeeTableRecords(array_values($records));
    }

    public function test_nested_groups_keep_supplier_week_filters_and_search(): void
    {
        $firstSupplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Go Fruit']);
        $secondSupplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Fruit Active']);
        $included = SupplierProduct::create(['producer_id' => $firstSupplier->id, 'name' => 'Mere Gala', 'status' => 'active', 'currency' => 'EUR', 'created_at' => '2026-09-28']);
        $wrongWeek = SupplierProduct::create(['producer_id' => $firstSupplier->id, 'name' => 'Mere Gala vechi', 'status' => 'active', 'currency' => 'EUR', 'created_at' => '2026-09-21']);
        $wrongSupplier = SupplierProduct::create(['producer_id' => $secondSupplier->id, 'name' => 'Mere Active', 'status' => 'active', 'currency' => 'EUR', 'created_at' => '2026-09-28']);

        Livewire::test(ListSupplierProducts::class)
            ->filterTable('producer_id', $firstSupplier->id)
            ->filterTable('created_week', ['date' => '2026-09-30'])
            ->searchTable('Mere')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$included])
            ->assertCanNotSeeTableRecords([$wrongWeek, $wrongSupplier])
            ->assertSee('data-supplier-group="'.$firstSupplier->id.'"', escape: false)
            ->assertDontSee('data-supplier-group="'.$secondSupplier->id.'"', escape: false)
            ->searchTable('No matching product')
            ->assertCountTableRecords(0);
    }

    public function test_nested_groups_repeat_supplier_and_week_headers_after_pagination(): void
    {
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Go Fruit']);

        foreach (range(1, 12) as $index) {
            SupplierProduct::create(['producer_id' => $supplier->id, 'name' => sprintf('Product %02d', $index), 'status' => 'active', 'currency' => 'EUR', 'created_at' => '2026-09-28']);
        }

        $test = Livewire::test(ListSupplierProducts::class)
            ->set('tableRecordsPerPage', 10)
            ->assertSuccessful();

        $this->assertCount(10, $test->instance()->getTableRecords());

        $test->call('gotoPage', 2)->assertSuccessful()
            ->assertSee('data-supplier-group="'.$supplier->id.'"', escape: false)
            ->assertSee('data-supplier-week="supplier:'.$supplier->id.':week:2026-09-28"', escape: false)
            ->assertSee('Product 11')
            ->assertSee('Product 12');

        $this->assertCount(2, $test->instance()->getTableRecords());
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

    public function test_create_persists_unknown_moq_as_null_without_inferring_it_from_packaging(): void
    {
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);

        Livewire::test(CreateSupplierProduct::class)
            ->fillForm([
                'producer_id' => $supplier->id,
                'name' => 'Mere fără MOQ',
                'currency' => 'EUR',
                'min_quantity_unit' => 'kg',
                'package_size' => '10',
                'unit_price' => '2.50',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = $supplier->supplierProducts()->sole();
        $this->assertNull($product->min_quantity_value);
        $this->assertSame('10.0000', $product->package_size);
        $this->assertSame('kg', $product->min_quantity_unit);
        $this->assertSame('2.5000', $product->unit_price);
        $this->assertCount(0, $product->prices);
        $this->assertDatabaseHas('supplier_products', ['id' => $product->id, 'min_quantity_value' => null]);
    }

    public function test_edit_can_clear_moq_and_keeps_unit_required(): void
    {
        $supplier = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $product = $supplier->supplierProducts()->create([
            'name' => 'Mere',
            'min_quantity_value' => 10,
            'min_quantity_unit' => 'kg',
            'unit_price' => 2.5,
            'currency' => 'EUR',
        ]);

        Livewire::test(EditSupplierProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['min_quantity_value' => '', 'min_quantity_unit' => null])
            ->call('save')
            ->assertHasFormErrors(['min_quantity_unit' => 'required'])
            ->fillForm(['min_quantity_unit' => 'kg'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($product->fresh()->min_quantity_value);
        $this->assertDatabaseHas('supplier_products', ['id' => $product->id, 'min_quantity_value' => null]);
    }

    public function test_supplier_product_uses_the_image_gallery_component(): void
    {
        Storage::fake('public');
        $producer = Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Ferma Verde']);
        $component = Livewire::test(CreateSupplierProduct::class);
        $uploaderKey = collect($component->get('data.images'))
            ->search(fn (array $item): bool => (bool) ($item['_is_uploader'] ?? false));

        $this->assertIsString($uploaderKey);

        $component
            ->set("data.images.{$uploaderKey}.path", [UploadedFile::fake()->image('supplier.jpg', 320, 240)])
            ->call('callSchemaComponentMethod', 'form.images', 'promotePendingUploaderFiles')
            ->fillForm([
                'producer_id' => $producer->id,
                'name' => 'Supplier gallery product',
                'status' => 'active',
                'currency' => 'RON',
                'min_quantity_unit' => 'kg',
                'min_quantity_value' => '10',
                'unit_price' => '3.50',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = SupplierProduct::query()->where('name', 'Supplier gallery product')->firstOrFail();

        $this->assertSame(
            $product->imageForRole('main', 'supplier-products')?->path,
            $product->display_image_path,
        );
        $this->assertStringEndsWith('.webp', $product->display_image_path);
    }

    public function test_type_column_is_dropped_in_favour_of_quality(): void
    {
        $this->assertFalse(Schema::hasColumn('supplier_products', 'type'));
        $this->assertTrue(Schema::hasColumn('supplier_products', 'quality'));
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
