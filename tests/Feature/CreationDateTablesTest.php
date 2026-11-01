<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\BestPrices\Filament\Pages\BestPricesSupermarket;
use App\Modules\BestPrices\Filament\Pages\BestPricesSuppliers;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages\ListSupermarketPrices;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\CreationDateTable;
use Database\Factories\SupermarketFactory;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class CreationDateTablesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'is_active' => true]);
        Filament::setCurrentPanel('admin');
        setPermissionsTeamId($this->tenant->getKey());
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $this->tenant->getKey()]);
        $user = User::factory()->create();
        $this->tenant->users()->attach($user, ['role' => 'super_admin']);
        $user->assignRole($role);
        session(['tenant_id' => $this->tenant->getKey()]);
        $this->actingAs($user);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function tablePages(): array
    {
        return [
            'supplier products' => [ListSupplierProducts::class],
            'supermarket prices' => [ListSupermarketPrices::class],
            'best supplier prices' => [BestPricesSuppliers::class],
            'best supermarket prices' => [BestPricesSupermarket::class],
        ];
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function comparisonPages(): array
    {
        return [
            'suppliers' => [BestPricesSuppliers::class],
            'supermarkets' => [BestPricesSupermarket::class],
        ];
    }

    #[DataProvider('tablePages')]
    public function test_weekly_view_is_default_and_can_be_disabled(string $page): void
    {
        app()->setLocale('ro');

        $record = $this->createRecord($page, '2025-12-31 12:00:00');
        $test = Livewire::test($page)
            ->assertSuccessful()
            ->assertSet('tableGrouping', 'created_week:asc')
            ->assertCanSeeTableRecords([$record])
            ->assertSee('29.12.2025 – 04.01.2026');

        $this->assertSame('Pe săptămâni', $test->instance()->getTable()->getGroupRecordsTriggerAction()->getLabel());

        $test->set('tableGrouping', null)->assertSuccessful()->assertCanSeeTableRecords([$record]);
        $this->assertNull($test->instance()->getTableGrouping());
        $this->assertSame('Vizualizare', $test->instance()->getTable()->getGroupRecordsTriggerAction()->getLabel());
    }

    #[DataProvider('tablePages')]
    public function test_creation_week_includes_monday_through_sunday_across_years(string $page): void
    {
        $before = $this->createRecord($page, '2025-12-28 23:59:59');
        $monday = $this->createRecord($page, '2025-12-29 00:00:00');
        $sunday = $this->createRecord($page, '2026-01-04 23:59:59');
        $after = $this->createRecord($page, '2026-01-05 00:00:00');

        Livewire::test($page)
            ->filterTable('created_week', ['date' => '2026-01-04'])
            ->assertCanSeeTableRecords([$monday, $sunday])
            ->assertCanNotSeeTableRecords([$before, $after])
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$before, $monday, $sunday, $after]);
    }

    #[DataProvider('tablePages')]
    public function test_creation_date_range_is_inclusive_and_intersects_the_week(string $page): void
    {
        $before = $this->createRecord($page, '2025-12-30 23:59:59');
        $first = $this->createRecord($page, '2025-12-31 00:00:00');
        $last = $this->createRecord($page, '2025-12-31 23:59:59');
        $after = $this->createRecord($page, '2026-01-01 00:00:00');

        Livewire::test($page)
            ->filterTable('created_at', ['from' => '2025-12-31', 'until' => '2025-12-31'])
            ->assertCanSeeTableRecords([$first, $last])
            ->assertCanNotSeeTableRecords([$before, $after])
            ->filterTable('created_week', ['date' => '2025-12-31'])
            ->assertCanSeeTableRecords([$first, $last])
            ->filterTable('created_week', ['date' => '2026-01-07'])
            ->assertCountTableRecords(0);
    }

    #[DataProvider('tablePages')]
    public function test_groups_stay_together_before_other_sorting(string $page): void
    {
        $earlier = $this->createRecord($page, '2025-12-30 09:00:00', 50);
        $later = $this->createRecord($page, '2026-01-06 09:00:00', 10);
        $sameWeek = $this->createRecord($page, '2026-01-01 09:00:00', 5);
        $test = Livewire::test($page)->assertSuccessful();
        $instance = $test->instance();
        $records = $instance->getFilteredSortedTableQuery()->get();
        $group = $instance->getTableGrouping();

        $this->assertEqualsCanonicalizing([$earlier->id, $sameWeek->id], $records->take(2)->modelKeys());
        $this->assertSame($later->id, $records->last()->id);
        $this->assertSame('2025-12-29', $group->getStringKey($records->first()));

        $test->set('tableGrouping', 'created_week:desc')->assertSuccessful();
        $this->assertSame($later->id, $test->instance()->getFilteredSortedTableQuery()->first()->id);
    }

    #[DataProvider('comparisonPages')]
    public function test_comparisons_filter_source_prices_before_ranking_and_clear_stale_selection(string $page): void
    {
        $canonical = CanonicalProduct::factory()->create(['created_at' => '2020-01-01']);
        $inside = $this->createComparedPrice($page, $canonical, '2025-12-31 12:00:00', 10);
        $outsideAmount = $page === BestPricesSuppliers::class ? 1 : 100;
        $outside = $this->createComparedPrice($page, $canonical, '2026-01-07 12:00:00', $outsideAmount, $inside);
        $empty = CanonicalProduct::factory()->create();
        $selection = $page === BestPricesSuppliers::class ? 'selectedSupplierByCanonical' : 'selectedSupermarketByCanonical';
        $method = $page === BestPricesSuppliers::class ? 'bestSupplierProductId' : 'bestSupermarketPriceId';
        $toggle = $page === BestPricesSuppliers::class ? 'toggleSuppliers' : 'toggleSupermarkets';

        $test = Livewire::test($page)->assertSuccessful();
        $this->assertSame($outside->id, $test->instance()->{$method}($canonical));

        $test->call('toggleProductSelection', $canonical->id, $outside->id)
            ->call($toggle, $canonical->id)
            ->filterTable('created_week', ['date' => '2025-12-31'])
            ->assertSet($selection, [])
            ->assertSet('expandedCanonicalIds', [])
            ->assertCanSeeTableRecords([$canonical])
            ->assertCanNotSeeTableRecords([$empty]);

        $this->assertSame($inside->id, $test->instance()->{$method}($canonical));
        $this->assertSame('2025-12-31 12:00:00', $test->instance()->getFilteredSortedTableQuery()->first()->comparison_created_at);

        $test->call($toggle, $canonical->id)->assertSuccessful();
        $this->assertSame([$inside->id], array_column($test->instance()->expandedCanonicalIds[$canonical->id], 'id'));

        $test->removeTableFilter('created_week')->assertCanSeeTableRecords([$canonical, $empty]);
        $this->assertSame($outside->id, $test->instance()->{$method}($canonical));
    }

    public function test_week_scoping_matches_the_group_key_without_mutating_the_creation_date(): void
    {
        $record = $this->createRecord(ListSupplierProducts::class, '2025-12-31 12:00:00');
        $outside = $this->createRecord(ListSupplierProducts::class, '2026-01-05 00:00:00');
        $test = Livewire::test(ListSupplierProducts::class);
        $group = $test->instance()->getTableGrouping();

        $this->assertSame('2025-12-29', $group->getStringKey($record));
        $this->assertSame('2025-12-31 12:00:00', $record->created_at->toDateTimeString());
        $this->assertSame([$record->id], $group->scopeQueryByKey(SupplierProduct::query(), '2025-12-29')->pluck('id')->all());
        $this->assertNotEquals($group->getStringKey($record), $group->getStringKey($outside));
        $this->assertSame('29.12.2025 – 04.01.2026', CreationDateTable::weekLabel('2026-01-04'));
    }

    private function createRecord(string $page, string $createdAt, float $amount = 10): Model
    {
        if (in_array($page, [BestPricesSuppliers::class, BestPricesSupermarket::class], true)) {
            $canonical = CanonicalProduct::factory()->create(['created_at' => '2020-01-01']);
            $this->createComparedPrice($page, $canonical, $createdAt, $amount);

            return $canonical;
        }

        if ($page === ListSupermarketPrices::class) {
            return SupermarketPrice::factory()->create(['created_at' => $createdAt, 'price' => $amount]);
        }

        return $this->createSupplierProduct($createdAt, $amount);
    }

    private function createComparedPrice(string $page, CanonicalProduct $canonical, string $createdAt, float $amount, ?Model $previous = null): Model
    {
        if ($page === BestPricesSuppliers::class) {
            $product = $this->createSupplierProduct($createdAt, $amount);
            $canonical->supplierProducts()->attach($product);

            return $product;
        }

        $product = $previous?->product ?? SupermarketProduct::factory()->create();
        $canonical->supermarketProducts()->syncWithoutDetaching([$product->id]);

        return SupermarketPrice::factory()->create([
            'supermarket_product_id' => $product->id,
            'supermarket_id' => $previous?->supermarket_id ?? SupermarketFactory::new()->create()->id,
            'created_at' => $createdAt,
            'price' => $amount,
        ]);
    }

    private function createSupplierProduct(string $createdAt, float $amount): SupplierProduct
    {
        $supplier = Supplier::firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'Test supplier']);

        return SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => fake()->words(2, true),
            'status' => 'active',
            'unit_price' => $amount,
            'currency' => 'EUR',
            'created_at' => $createdAt,
        ]);
    }
}
