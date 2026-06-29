<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\BestPrices\Filament\Pages\BestPricesSuppliers;
use App\Modules\BestPrices\Filament\Pages\BestPricesSupermarket;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class BestPricesPagesTest extends TestCase
{
    use RefreshDatabase;

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
        $customer = Customer::create(['name' => 'Client SRL', 'tenant_id' => $tenant->id]);

        Livewire::test(BestPricesSuppliers::class)
            ->call('toggleProductSelection', $canonical->id, $bestId)
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
        $supermarketId = Customer::query()->withoutGlobalScope('active_tenant')->global()->value('id');

        $this->actingAs($user);
        $this->grantSupermarketPageAccess($user, $tenant);

        Livewire::test(BestPricesSupermarket::class)
            ->call('toggleProductSelection', $canonical->id, $bestId)
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
