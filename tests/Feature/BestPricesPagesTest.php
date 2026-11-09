<?php

namespace Tests\Feature;

use App\Models\CurrencyExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\BestPrices\Filament\Pages\BestPricesSupermarket;
use App\Modules\BestPrices\Filament\Pages\BestPricesSuppliers;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Models\Supplier;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class BestPricesPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CurrencyExchangeRate::factory()->create();
        CurrencyExchangeRate::factory()->inverse()->create();
    }

    /**
     * Grant the supermarket-price permission the sales pages gate on, without
     * the super_admin role (which would bypass the tenant scope these tests assert).
     */
    private function grantSupermarketPageAccess(User $user, Tenant $tenant): void
    {
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']),
        );
    }

    public function test_both_best_prices_pages_are_registered_as_filament_routes(): void
    {
        $this->assertTrue(class_exists(BestPricesSuppliers::class));
        $this->assertTrue(class_exists(BestPricesSupermarket::class));
        $this->assertTrue(Route::has('filament.admin.pages.best-prices-suppliers'));
        $this->assertTrue(Route::has('filament.admin.pages.best-prices-supermarket'));
    }

    public function test_suppliers_page_orders_canonical_products_by_cheapest_landed_cost(): void
    {
        $cheap = $this->canonicalWithSupplier('Rosii', landedCost: 4.0);
        $expensive = $this->canonicalWithSupplier('Ardei', landedCost: 6.0);
        $noSupplier = CanonicalProduct::factory()->create(['name' => 'Castraveti']);

        $ids = app(BestPricesSuppliers::class)->getQuery()->pluck('id')->all();

        $this->assertSame(
            [$cheap->id, $expensive->id, $noSupplier->id],
            $ids,
        );
    }

    public function test_suppliers_page_toggle_lists_all_supplier_candidates_cheapest_first(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Mere']);
        $this->attachSupplier($canonical, 'Supplier A', landedCost: 5.0);
        $this->attachSupplier($canonical, 'Supplier B', landedCost: 3.0);

        $page = app(BestPricesSuppliers::class);

        $this->assertSame([], $page->expandedCanonicalIds);

        $page->toggleSuppliers($canonical->id);

        $this->assertArrayHasKey($canonical->id, $page->expandedCanonicalIds);
        $breakdown = $page->expandedCanonicalIds[$canonical->id];
        $this->assertCount(2, $breakdown);
        $this->assertSame('Supplier B', $breakdown[0]['name']);
        $this->assertSame(3.0, $breakdown[0]['landed_cost']);
        $this->assertSame('Supplier A', $breakdown[1]['name']);

        $page->toggleSuppliers($canonical->id);
        $this->assertArrayNotHasKey($canonical->id, $page->expandedCanonicalIds);
    }

    public function test_supplier_week_groups_preserve_selectable_rows_and_calendar_offsets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-05 12:00:00'));
        $canonical = CanonicalProduct::factory()->create(['name' => 'Varza alba']);

        foreach ([['2026-01-05', 5], ['2026-01-05', 3], ['2026-01-04', 2], ['2025-12-21', 8]] as $index => [$date, $price]) {
            $this->attachSupplier($canonical, 'Supplier '.$index, landedCost: $price);
            SupplierProduct::query()->latest('id')->first()->update(['created_at' => $date.' 12:00:00']);
        }

        $page = app(BestPricesSuppliers::class);
        $page->toggleSuppliers($canonical->id);
        $weeks = $page->supplierWeeks($canonical->id);

        $this->assertSame(['2026-01-05', '2025-12-29', '2025-12-15'], $weeks->keys()->all());
        $this->assertSame([1, 2, 4], $weeks->pluck('week_number')->all());
        $this->assertCount(2, $weeks->first()['suppliers']);
        $this->assertSame(3.0, $weeks->first()['best_price']);
        $supplier = $weeks->first()['suppliers']->first();
        $this->assertSame(3.0, $supplier['landed_cost']);
        $this->assertSame(100.0, $supplier['quantity']);
        $page->toggleSupplierCandidate($canonical->id, $supplier['id']);
        $page->toggleSuppliers($canonical->id);
        $page->toggleSuppliers($canonical->id);
        $this->assertTrue($page->isSupplierCandidateSelected($canonical->id, $supplier['id']));
    }

    public function test_suppliers_page_toggle_exposes_candidate_id_for_selection(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Pere']);
        $this->attachSupplier($canonical, 'Supplier A', landedCost: 5.0);

        $page = app(BestPricesSuppliers::class);
        $page->toggleSuppliers($canonical->id);

        $candidate = $page->expandedCanonicalIds[$canonical->id][0];
        $this->assertArrayHasKey('id', $candidate);
        $this->assertSame(
            SupplierProduct::query()->where('name', $canonical->name)->value('id'),
            $candidate['id'],
        );
    }

    public function test_suppliers_page_pins_one_supplier_per_product_and_toggles_off(): void
    {
        $page = app(BestPricesSuppliers::class);

        $page->toggleSupplierCandidate(7, 100);
        $this->assertTrue($page->isSupplierCandidateSelected(7, 100));
        $this->assertSame([7 => 100], $page->selectedSupplierByCanonical);

        // Picking a different supplier in the same product replaces the choice.
        $page->toggleSupplierCandidate(7, 200);
        $this->assertFalse($page->isSupplierCandidateSelected(7, 100));
        $this->assertTrue($page->isSupplierCandidateSelected(7, 200));

        // Re-picking the same supplier clears it.
        $page->toggleSupplierCandidate(7, 200);
        $this->assertFalse($page->isSupplierCandidateSelected(7, 200));
        $this->assertSame([], $page->selectedSupplierByCanonical);
    }

    public function test_suppliers_main_row_checkbox_selects_best_supplier_and_shares_state_with_breakdown(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Struguri']);
        $this->attachSupplier($canonical, 'Cheapest', landedCost: 3.0);
        $this->attachSupplier($canonical, 'Pricier', landedCost: 8.0);

        $page = app(BestPricesSuppliers::class);
        $bestId = $page->bestSupplierProductId($canonical->refresh());

        // Main-row checkbox includes the product using its cheapest supplier.
        $page->toggleProductSelection($canonical->id, $bestId);
        $this->assertTrue($page->isProductSelected($canonical->id));
        $this->assertTrue($page->isSupplierCandidateSelected($canonical->id, $bestId));
        $this->assertSame([$canonical->id => $bestId], $page->selectedSupplierByCanonical);

        // Picking a different supplier in the breakdown keeps the product selected.
        $pricierId = SupplierProduct::query()->where('name', $canonical->name)
            ->where('id', '!=', $bestId)->value('id');
        $page->toggleSupplierCandidate($canonical->id, $pricierId);
        $this->assertTrue($page->isProductSelected($canonical->id));
        $this->assertSame($pricierId, $page->selectedSupplierByCanonical[$canonical->id]);

        // Main-row checkbox toggles the whole product off again.
        $page->toggleProductSelection($canonical->id, $bestId);
        $this->assertFalse($page->isProductSelected($canonical->id));
        $this->assertSame([], $page->selectedSupplierByCanonical);
    }

    public function test_supermarket_page_orders_canonical_products_by_highest_shelf_price(): void
    {
        $high = $this->canonicalWithSupermarketPrice('Banane', grossPrice: 12.0);
        $low = $this->canonicalWithSupermarketPrice('Lamai', grossPrice: 7.0);
        $noPrice = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $ids = app(BestPricesSupermarket::class)->getQuery()->pluck('id')->all();

        $this->assertSame(
            [$high->id, $low->id, $noPrice->id],
            $ids,
        );
    }

    public function test_supermarket_page_toggle_lists_all_observed_prices_highest_first(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Capsuni']);
        $this->attachSupermarketPrice($canonical, 'Kaufland', grossPrice: 8.0);
        $this->attachSupermarketPrice($canonical, 'Auchan', grossPrice: 11.0);

        $page = app(BestPricesSupermarket::class);

        $page->toggleSupermarkets($canonical->id);

        $breakdown = $page->expandedCanonicalIds[$canonical->id];
        $this->assertCount(2, $breakdown);
        $this->assertSame('Auchan', $breakdown[0]['name']);
        $this->assertSame(11.0, $breakdown[0]['gross_price']);
        $this->assertSame('Kaufland', $breakdown[1]['name']);
    }

    public function test_supermarket_week_groups_preserve_prices_selection_and_calendar_offsets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-05 12:00:00'));
        $canonical = CanonicalProduct::factory()->create(['name' => 'Mere Gala']);

        foreach ([['2026-01-05', 8], ['2026-01-05', 11], ['2026-01-04', 20], ['2025-12-21', 6]] as $index => [$date, $price]) {
            $this->attachSupermarketPrice($canonical, 'Market '.$index, grossPrice: $price);
            SupermarketPrice::query()->latest('id')->firstOrFail()->update(['created_at' => $date.' 12:00:00']);
        }

        $page = app(BestPricesSupermarket::class);
        $this->assertTrue($page->supermarketWeeks($canonical->id)->isEmpty());

        $page->toggleSupermarkets($canonical->id);
        $weeks = $page->supermarketWeeks($canonical->id);

        $this->assertSame(['2026-01-05', '2025-12-29', '2025-12-15'], $weeks->keys()->all());
        $this->assertSame([1, 2, 4], $weeks->pluck('week_number')->all());
        $this->assertSame([11.0, 20.0, 6.0], $weeks->pluck('best_price')->all());
        $this->assertSame(['Market 1', 'Market 0'], $weeks->first()['supermarkets']->pluck('name')->all());
        $candidate = $weeks->first()['supermarkets']->first();
        $this->assertSame(11.0, $candidate['gross_price']);
        $this->assertSame(11.0, $candidate['price_excl_vat']);
        $this->assertSame('2026-01-05', $candidate['observed_at']);

        $page->toggleSupermarketCandidate($canonical->id, $candidate['id']);
        $page->toggleSupermarkets($canonical->id);
        $this->assertTrue($page->supermarketWeeks($canonical->id)->isEmpty());
        $page->toggleSupermarkets($canonical->id);
        $this->assertTrue($page->isSupermarketCandidateSelected($canonical->id, $candidate['id']));
    }

    public function test_supermarket_breakdown_renders_each_week_with_its_own_ranked_prices(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-05 12:00:00'));
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $this->grantSupermarketPageAccess($user, $tenant);

        $canonical = CanonicalProduct::factory()->create(['name' => 'Mere Gala']);
        foreach ([
            ['Current cheaper', '2026-01-05', 8],
            ['Current highest', '2026-01-05', 11],
            ['Current tied', '2026-01-05', 11],
            ['Previous highest', '2026-01-04', 20],
        ] as [$name, $date, $price]) {
            $this->attachSupermarketPrice($canonical, $name, grossPrice: $price);
            SupermarketPrice::query()->latest('id')->firstOrFail()->update(['created_at' => $date.' 12:00:00']);
        }

        $page = Livewire::test(BestPricesSupermarket::class)
            ->call('toggleSupermarkets', $canonical->id)
            ->assertSuccessful()
            ->assertSee('WK1')
            ->assertSee('WK2');

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new \DOMXPath($document);
        $weeks = $xpath->query('//div[contains(@class, "fi-best-prices-supermarket-breakdown")]/details');

        $this->assertCount(2, $weeks);
        $this->assertTrue($weeks->item(0)->hasAttribute('open'));
        $this->assertFalse($weeks->item(1)->hasAttribute('open'));
        $this->assertTrue($weeks->item(0)->hasAttribute('wire:ignore.self'));
        $this->assertStringContainsString('WK1', $xpath->query('./summary', $weeks->item(0))->item(0)->textContent);
        $this->assertStringContainsString('WK2', $xpath->query('./summary', $weeks->item(1))->item(0)->textContent);
        $currentRows = $xpath->query('.//tbody/tr', $weeks->item(0));
        $this->assertCount(3, $currentRows);
        $this->assertStringContainsString(__('Most expensive'), $currentRows->item(0)->textContent);
        $this->assertStringContainsString(__('Most expensive'), $currentRows->item(1)->textContent);
        $this->assertStringNotContainsString(__('Most expensive'), $currentRows->item(2)->textContent);
        $this->assertStringContainsString('Current cheaper', $currentRows->item(2)->textContent);
        $previousRows = $xpath->query('.//tbody/tr', $weeks->item(1));
        $this->assertCount(1, $previousRows);
        $this->assertStringContainsString('Previous highest', $previousRows->item(0)->textContent);
        $this->assertStringContainsString(__('Most expensive'), $previousRows->item(0)->textContent);
    }

    public function test_supermarket_breakdown_without_prices_has_no_week_groups(): void
    {
        $canonical = CanonicalProduct::factory()->create();
        $page = app(BestPricesSupermarket::class);
        $page->toggleSupermarkets($canonical->id);

        $this->assertSame([], $page->expandedCanonicalIds[$canonical->id]);
        $this->assertTrue($page->supermarketWeeks($canonical->id)->isEmpty());
    }

    #[DataProvider('undatedComparisonPages')]
    public function test_undated_comparison_prices_render_without_inventing_a_creation_week(string $pageClass, string $toggleMethod, string $weeksMethod, string $rowsKey): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $this->grantSupermarketPageAccess($user, $tenant);
        $canonical = CanonicalProduct::factory()->create(['name' => 'Mere']);

        if ($pageClass === BestPricesSuppliers::class) {
            $this->attachSupplier($canonical, 'Supplier A', landedCost: 4.0);
            SupplierProduct::query()->latest('id')->firstOrFail()->update(['created_at' => null]);
        } else {
            $this->attachSupermarketPrice($canonical, 'Market A', grossPrice: 4.0);
            SupermarketPrice::query()->latest('id')->firstOrFail()->update(['created_at' => null]);
        }

        $test = Livewire::test($pageClass)
            ->call($toggleMethod, $canonical->id)
            ->assertSuccessful()
            ->assertSee(__('Without creation date'));
        $weeks = $test->instance()->{$weeksMethod}($canonical->id);

        $this->assertSame([''], $weeks->keys()->all());
        $this->assertNull($weeks->first()['week_number']);
        $this->assertNull($weeks->first()['start']);
        $this->assertNull($weeks->first()['end']);
        $this->assertCount(1, $weeks->first()[$rowsKey]);
        $this->assertNull($weeks->first()[$rowsKey]->first()['created_at']);
    }

    public static function undatedComparisonPages(): array
    {
        return [
            'suppliers' => [BestPricesSuppliers::class, 'toggleSuppliers', 'supplierWeeks', 'suppliers'],
            'supermarkets' => [BestPricesSupermarket::class, 'toggleSupermarkets', 'supermarketWeeks', 'supermarkets'],
        ];
    }

    public function test_suppliers_page_renders_and_selects_a_supplier_through_livewire(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $this->grantSupermarketPageAccess($user, $tenant);

        $canonical = CanonicalProduct::factory()->create(['name' => 'Caise']);
        $this->attachSupplier($canonical, 'Supplier A', landedCost: 4.0);
        $bestId = SupplierProduct::query()->where('name', $canonical->name)->value('id');

        Livewire::test(BestPricesSuppliers::class)
            ->assertSuccessful()
            ->call('toggleProductSelection', $canonical->id, $bestId)
            ->assertSet('selectedSupplierByCanonical', [$canonical->id => $bestId])
            ->call('toggleSuppliers', $canonical->id)
            ->assertSee(__('Best'))
            ->assertSee('WK1')
            ->assertSee('toggleSupplierCandidate('.$canonical->id.', '.$bestId.')', escape: false)
            ->call('toggleSupplierCandidate', $canonical->id, $bestId)
            ->assertSet('selectedSupplierByCanonical', [])
            ->call('toggleSupplierCandidate', $canonical->id, $bestId)
            ->assertSet('selectedSupplierByCanonical', [$canonical->id => $bestId])
            ->assertSuccessful();
    }

    public function test_supermarket_page_renders_and_selects_a_price_through_livewire(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $this->grantSupermarketPageAccess($user, $tenant);

        $canonical = CanonicalProduct::factory()->create(['name' => 'Visine']);
        $this->attachSupermarketPrice($canonical, 'Kaufland', grossPrice: 9.0);
        $bestId = SupermarketPrice::query()->value('id');

        Livewire::test(BestPricesSupermarket::class)
            ->assertSuccessful()
            ->call('toggleProductSelection', $canonical->id, $bestId)
            ->assertSet('selectedSupermarketByCanonical', [$canonical->id => $bestId])
            ->call('toggleSupermarkets', $canonical->id)
            ->assertSee(__('Most expensive'))
            ->assertSee('toggleSupermarketCandidate('.$canonical->id.', '.$bestId.')', escape: false)
            ->call('toggleSupermarketCandidate', $canonical->id, $bestId)
            ->assertSet('selectedSupermarketByCanonical', [])
            ->call('toggleSupermarketCandidate', $canonical->id, $bestId)
            ->assertSet('selectedSupermarketByCanonical', [$canonical->id => $bestId])
            ->assertSuccessful();
    }

    public function test_creating_a_customer_offer_uses_the_users_active_tenant_without_an_explicit_session_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        $this->grantSupermarketPageAccess($user, $tenant);
        // Deliberately no session('tenant_id') and no Filament::setTenant():
        // a fresh visit must still resolve the user's own tenant.

        $canonical = CanonicalProduct::factory()->create(['name' => 'Mere']);
        $this->attachSupplier($canonical, 'Supplier A', landedCost: 4.0);
        $bestId = SupplierProduct::query()->where('name', $canonical->name)->value('id');
        $this->attachSupplier($canonical, 'Cheaper supplier', landedCost: 2.0);
        $customer = Customer::create(['name' => 'Client SRL', 'tenant_id' => $tenant->id]);

        Livewire::test(BestPricesSuppliers::class)
            ->call('toggleSuppliers', $canonical->id)
            ->call('toggleSupplierCandidate', $canonical->id, $bestId)
            ->callAction('createCustomerOffer', data: [
                'customer_id' => $customer->id,
                'currency' => 'EUR',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                'margin_value' => 10,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('customer_offers', [
            'customer_id' => $customer->id,
            'tenant_id' => $tenant->id,
        ]);
        $item = CustomerOffer::query()->latest('id')->first()->items()->first();
        $this->assertNotNull($item);
        $this->assertSame($bestId, $item->supplier_product_id);
        $this->assertSame('4.0000', $item->purchase_price);
    }

    public function test_creating_a_supermarket_offer_uses_the_users_active_tenant_without_an_explicit_session_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        // Build the global supermarket + price before authenticating, so they stay
        // tenant-less (global) just like seeded supermarkets.
        $canonical = CanonicalProduct::factory()->create(['name' => 'Visine']);
        $this->attachSupermarketPrice($canonical, 'Kaufland', grossPrice: 9.0);
        $bestId = SupermarketPrice::query()->value('id');
        $this->attachSupermarketPrice($canonical, 'Higher price market', grossPrice: 12.0);
        $this->attachSupplier($canonical, 'Supplier A', landedCost: 4.0);
        $supermarketId = Customer::query()->withoutGlobalScope('active_tenant')->global()->value('id');

        $this->actingAs($user);
        $this->grantSupermarketPageAccess($user, $tenant);

        Livewire::test(BestPricesSupermarket::class)
            ->call('toggleSupermarkets', $canonical->id)
            ->call('toggleSupermarketCandidate', $canonical->id, $bestId)
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarketId,
                'currency' => 'EUR',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
                'margin_value' => 0,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('customer_offers', [
            'customer_id' => $supermarketId,
            'tenant_id' => $tenant->id,
        ]);
        $item = CustomerOffer::query()->latest('id')->first()->items()->first();
        $this->assertNotNull($item);
        $this->assertSame('9.0000', $item->sale_price);
    }

    public function test_suppliers_offer_lines_expose_canonical_id_and_available_quantity(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Capsuni']);
        $this->attachSupplier($canonical, 'Supplier A', landedCost: 4.0);

        $page = app(BestPricesSuppliers::class);
        $page->toggleProductSelection($canonical->id, $page->bestSupplierProductId($canonical->refresh()));

        $selected = (new \ReflectionMethod($page, 'selectedCanonicalProducts'))->invoke($page);
        $lines = (new \ReflectionMethod($page, 'offerSelectionLines'))->invoke($page, $selected);

        $this->assertSame($canonical->id, $lines[0]['canonical_id']);
        $this->assertSame('Supplier A', $lines[0]['supplier']);
        $this->assertSame(100.0, $lines[0]['quantity_available']);
        // Supplier page has no supermarket (sell) side in the review.
        $this->assertArrayNotHasKey('supermarket', $lines[0]);
    }

    public function test_suppliers_offer_honours_the_edited_quantity_through_livewire(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        $this->grantSupermarketPageAccess($user, $tenant);

        $canonical = CanonicalProduct::factory()->create(['name' => 'Mure']);
        $this->attachSupplier($canonical, 'Supplier A', landedCost: 4.0);
        $bestId = SupplierProduct::query()->where('name', $canonical->name)->value('id');
        $customer = Customer::create(['name' => 'Client SRL', 'tenant_id' => $tenant->id]);

        Livewire::test(BestPricesSuppliers::class)
            ->call('toggleProductSelection', $canonical->id, $bestId)
            ->set("offerQuantities.{$canonical->id}", 30)
            ->callAction('createCustomerOffer', data: [
                'customer_id' => $customer->id,
                'currency' => 'EUR',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                'margin_value' => 10,
            ])
            ->assertHasNoActionErrors();

        $item = CustomerOffer::query()->latest('id')->first()?->items->first();
        $this->assertNotNull($item);
        $this->assertSame('30.0000', $item->quantity);
    }

    public function test_best_prices_order_products_using_a_common_currency(): void
    {
        $ronSupplier = $this->canonicalWithSupplier('RON supplier product', 6);
        $eurSupplier = $this->canonicalWithSupplier('EUR supplier product', 2);
        $eurSupplier->supplierProducts()->first()->update(['currency' => 'EUR']);

        $this->assertSame([$ronSupplier->id, $eurSupplier->id], app(BestPricesSuppliers::class)->getQuery()->whereKey([$ronSupplier->id, $eurSupplier->id])->pluck('id')->all());

        $ronMarket = $this->canonicalWithSupermarketPrice('RON market product', 9);
        $eurMarket = $this->canonicalWithSupermarketPrice('EUR market product', 3);
        SupermarketPrice::query()->where('supermarket_product_id', $eurMarket->supermarketProducts()->first()->id)->update(['currency' => 'EUR']);

        $this->assertSame([$eurMarket->id, $ronMarket->id], app(BestPricesSupermarket::class)->getQuery()->whereKey([$ronMarket->id, $eurMarket->id])->pluck('id')->all());
    }

    public function test_weekly_best_prices_compare_currencies_before_assigning_badges(): void
    {
        $supplierPage = app(BestPricesSuppliers::class);
        $supplierPage->expandedCanonicalIds[1] = [
            ['id' => 1, 'created_at' => now()->toDateTimeString(), 'unit_price' => 2, 'currency' => 'EUR'],
            ['id' => 2, 'created_at' => now()->toDateTimeString(), 'unit_price' => 6, 'currency' => 'RON'],
        ];
        $week = $supplierPage->supplierWeeks(1)->first();
        $this->assertSame(6.0, $week['best_price']);
        $this->assertSame('RON', $week['best_currency']);

        $supermarketPage = app(BestPricesSupermarket::class);
        $supermarketPage->expandedCanonicalIds[1] = [
            ['id' => 1, 'created_at' => now()->toDateTimeString(), 'gross_price' => 3, 'currency' => 'EUR'],
            ['id' => 2, 'created_at' => now()->toDateTimeString(), 'gross_price' => 9, 'currency' => 'RON'],
        ];
        $week = $supermarketPage->supermarketWeeks(1)->first();
        $this->assertSame(3.0, $week['best_price']);
        $this->assertSame('EUR', $week['best_currency']);
    }

    private function canonicalWithSupplier(string $name, float $landedCost): CanonicalProduct
    {
        $canonical = CanonicalProduct::factory()->create(['name' => $name]);
        $this->attachSupplier($canonical, $name.' Supplier', $landedCost);

        return $canonical;
    }

    private function attachSupplier(CanonicalProduct $canonical, string $supplierName, float $landedCost): void
    {
        $supplier = Supplier::create(['name' => $supplierName]);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => $canonical->name,
            'status' => 'active',
            'unit_price' => $landedCost,
            'currency' => 'RON',
            'quantity_available' => 100,
        ]);
        $canonical->supplierProducts()->attach($supplierProduct);
    }

    private function canonicalWithSupermarketPrice(string $name, float $grossPrice): CanonicalProduct
    {
        $canonical = CanonicalProduct::factory()->create(['name' => $name]);
        $this->attachSupermarketPrice($canonical, $name.' Market', $grossPrice);

        return $canonical;
    }

    private function attachSupermarketPrice(CanonicalProduct $canonical, string $supermarketName, float $grossPrice): void
    {
        $supermarketProduct = SupermarketProduct::factory()->create(['name' => $canonical->name, 'vat_rate' => 0]);
        $canonical->supermarketProducts()->attach($supermarketProduct);

        $supermarket = Customer::create(['name' => $supermarketName, 'tenant_id' => null]);
        SupermarketPrice::create([
            'supermarket_id' => $supermarket->id,
            'supermarket_product_id' => $supermarketProduct->id,
            'price' => $grossPrice,
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
    }
}
