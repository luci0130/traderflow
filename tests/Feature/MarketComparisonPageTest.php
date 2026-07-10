<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\SupplierOffersRelationManager;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Filament\Pages\MarketComparison;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Models\SupplierCostDefault;
use App\Modules\MarketComparison\Services\MarketComparisonRowAssembler;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\Product;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketComparisonPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_comparison_page_is_registered_as_a_filament_route(): void
    {
        $this->assertTrue(class_exists(MarketComparison::class));
        $this->assertTrue(Route::has('filament.admin.pages.market-comparison'));
    }

    public function test_assembler_computes_margin_between_best_supermarket_and_best_supplier(): void
    {
        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        $row = app(MarketComparisonRowAssembler::class)->assemble($canonical);

        $this->assertNotNull($row->bestSupplier);
        $this->assertNotNull($row->bestSupermarket);
        $this->assertSame(6.0, $row->bestSupplier->landedCost);
        $this->assertSame(9.0, $row->bestSupermarket->grossPrice);
        // Supermarket product VAT rate is 0 in the helper, so excl VAT == gross.
        $this->assertSame(3.0, $row->margin);
        $this->assertSame(50.0, $row->marginPercent);
    }

    public function test_assembler_leaves_margin_null_when_one_side_is_missing(): void
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 5,
        ]);
        $canonical->supplierProducts()->attach($supplierProduct);

        $row = app(MarketComparisonRowAssembler::class)->assemble($canonical);

        $this->assertNotNull($row->bestSupplier);
        $this->assertNull($row->bestSupermarket);
        $this->assertNull($row->margin);
        $this->assertFalse($row->hasMargin());
    }

    public function test_builder_creates_a_draft_supermarket_offer_with_supplier_and_product_data(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            [
                'customer_id' => $supermarket->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ],
            $tenant->id,
        );

        $this->assertInstanceOf(CustomerOffer::class, $offer);
        $this->assertSame('draft', $offer->status);
        $this->assertSame($supermarket->id, $offer->customer_id);
        $this->assertCount(1, $offer->items);

        $item = $offer->items->first();
        $this->assertSame('6.0000', $item->purchase_price);
        $this->assertSame('9.0000', $item->sale_price);

        // A tenant product is created/linked for the canonical product.
        $product = Product::query()->where('tenant_id', $tenant->id)->where('name', 'Portocale')->first();
        $this->assertNotNull($product);
        $this->assertSame($product->id, $item->product_id);
    }

    public function test_builder_defaults_item_quantity_to_the_suppliers_available_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            [
                'customer_id' => $supermarket->id,
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ],
            $tenant->id,
        );

        // The helper's supplier product has 100 units available.
        $this->assertSame('100.0000', $offer->items->first()->quantity);
    }

    public function test_builder_uses_the_supplied_quantity_clamped_to_what_is_available(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        // A within-range quantity is kept as-is.
        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            ['customer_id' => $supermarket->id, 'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET],
            $tenant->id,
            quantities: [$canonical->id => 40],
        );
        $this->assertSame('40.0000', $offer->items->first()->quantity);

        // Exceeding the available quantity (100) clamps down to the maximum.
        $clamped = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            ['customer_id' => $supermarket->id, 'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET],
            $tenant->id,
            quantities: [$canonical->id => 9999],
        );
        $this->assertSame('100.0000', $clamped->items->first()->quantity);
    }

    public function test_offer_selection_lines_expose_canonical_id_and_available_quantity_for_the_modal(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        $page = app(MarketComparison::class);
        $lines = (new \ReflectionMethod($page, 'offerSelectionLines'))
            ->invoke($page, new EloquentCollection([$canonical]));

        $this->assertSame($canonical->id, $lines[0]['canonical_id']);
        $this->assertSame(100.0, $lines[0]['quantity_available']);
    }

    public function test_quantities_seed_a_default_and_drop_unselected_products(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        $page = app(MarketComparison::class);
        $page->toggleSupplierPriority($canonical->id, $page->bestSupplierProductId($canonical));

        // Rendering the modal footer seeds the default quantity (max available).
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(100.0, $page->offerQuantities[$canonical->id]);

        // A user edit is preserved across re-renders.
        $page->offerQuantities[$canonical->id] = 25;
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(25, $page->offerQuantities[$canonical->id]);

        // Deselecting the product drops its quantity entry.
        $page->toggleSupplierPriority($canonical->id, $page->bestSupplierProductId($canonical));
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertArrayNotHasKey($canonical->id, $page->offerQuantities);
    }

    public function test_units_seed_kilograms_by_default_and_drop_unselected_products(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $kg = Unit::create(['tenant_id' => null, 'name' => 'Kilogram', 'symbol' => 'kg']);
        $tonne = Unit::create(['tenant_id' => null, 'name' => 'Tonă', 'symbol' => 't']);

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        $page = app(MarketComparison::class);
        $page->toggleSupplierPriority($canonical->id, $page->bestSupplierProductId($canonical));

        // Rendering the modal footer seeds the default unit (kilograms).
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame($kg->id, $page->offerUnits[$canonical->id]);

        // A user choice is preserved across re-renders.
        $page->offerUnits[$canonical->id] = $tonne->id;
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame($tonne->id, $page->offerUnits[$canonical->id]);

        // Deselecting the product drops its unit entry.
        $page->toggleSupplierPriority($canonical->id, $page->bestSupplierProductId($canonical));
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertArrayNotHasKey($canonical->id, $page->offerUnits);
    }

    public function test_offer_built_through_livewire_stores_the_selected_unit(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $kg = Unit::create(['tenant_id' => null, 'name' => 'Kilogram', 'symbol' => 'kg']);
        $tonne = Unit::create(['tenant_id' => null, 'name' => 'Tonă', 'symbol' => 't']);

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        // Defaults to kilograms when the reviewer leaves the selector untouched.
        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarket->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasNoActionErrors();

        $item = CustomerOffer::query()->latest('id')->first()?->items->first();
        $this->assertNotNull($item);
        $this->assertSame($kg->id, $item->unit_id);

        // An explicitly chosen unit is honoured.
        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->set("offerUnits.{$canonical->id}", $tonne->id)
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarket->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasNoActionErrors();

        $item = CustomerOffer::query()->latest('id')->first()?->items->first();
        $this->assertSame($tonne->id, $item->unit_id);
    }

    public function test_offer_built_through_livewire_honours_the_edited_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->set("offerQuantities.{$canonical->id}", 30)
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarket->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasNoActionErrors();

        $item = CustomerOffer::query()->latest('id')->first()?->items->first();
        $this->assertNotNull($item);
        $this->assertSame('30.0000', $item->quantity);
    }

    public function test_builder_attaches_prioritized_suppliers_to_the_offer_line_and_creates_no_supplier_offer(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $supplierOne = Supplier::create(['name' => 'Ferma Unu SRL']);
        $supplierTwo = Supplier::create(['name' => 'Ferma Doi SRL']);

        $apple = CanonicalProduct::factory()->create(['name' => 'Mere']);
        $appleFromOne = SupplierProduct::create([
            'producer_id' => $supplierOne->id, 'name' => 'Mere unu', 'status' => 'active',
            'unit_price' => 4.0, 'currency' => 'RON', 'quantity_available' => 100,
        ]);
        $appleFromTwo = SupplierProduct::create([
            'producer_id' => $supplierTwo->id, 'name' => 'Mere doi', 'status' => 'active',
            'unit_price' => 6.0, 'currency' => 'RON', 'quantity_available' => 80,
        ]);
        $apple->supplierProducts()->attach($appleFromOne);
        $apple->supplierProducts()->attach($appleFromTwo);

        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$apple]),
            ['customer_id' => $supermarket->id, 'currency' => 'RON', 'sale_mode' => SupermarketOfferBuilder::SALE_FROM_FIXED, 'margin_value' => 1],
            $tenant->id,
            // Prioritize supplier two first, then supplier one.
            supplierPriorities: [$apple->id => [$appleFromTwo->id, $appleFromOne->id]],
        );

        // The offer is the single entity: sourcing lives on its line, no separate
        // supplier offer is generated.
        $this->assertSame(0, SupplierOffer::count());

        $item = $offer->items->firstWhere('product.name', 'Mere');
        $this->assertNull($item->supplier_offer_item_id);

        $suppliers = $item->suppliers()->get();
        $this->assertCount(2, $suppliers);
        $this->assertSame($appleFromTwo->id, $suppliers[0]->supplier_product_id);
        $this->assertSame(1, $suppliers[0]->priority);
        $this->assertSame('6.0000', $suppliers[0]->unit_price);
        $this->assertSame($appleFromOne->id, $suppliers[1]->supplier_product_id);
        $this->assertSame(2, $suppliers[1]->priority);
        $this->assertSame('pending', $suppliers[0]->status);
    }

    public function test_create_offer_modal_leaves_offer_number_blank_for_auto_generation(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->mountAction('createSupermarketOffer')
            // The number field is prefilled with the previewed next sequence value
            // so the user can see which id the offer will get.
            ->assertActionDataSet([
                'offer_number' => 'OC-00001',
                'valid_until' => today()->addDays(7)->toDateString(),
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
            ]);
    }

    public function test_create_offer_through_the_modal_auto_numbers_from_the_sequence(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarket->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('OC-00001', CustomerOffer::query()->latest('id')->first()?->offer_number);
    }

    public function test_create_offer_rejects_a_duplicate_offer_number_for_the_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        // An existing offer already uses OC-1342 for this tenant.
        CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $supermarket->id, 'offer_number' => 'OC-1342',
            'offer_date' => today(), 'currency' => 'RON', 'status' => 'draft', 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        // Re-using OC-1342 must fail validation instead of throwing a 500.
        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->callAction('createSupermarketOffer', data: [
                'tenant_id' => $tenant->id,
                'customer_id' => $supermarket->id,
                'offer_number' => 'OC-1342',
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasActionErrors(['offer_number']);

        $this->assertSame(1, CustomerOffer::query()->where('offer_number', 'OC-1342')->count());

        // Bumping the number to a free value succeeds.
        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->callAction('createSupermarketOffer', data: [
                'tenant_id' => $tenant->id,
                'customer_id' => $supermarket->id,
                'offer_number' => 'OC-1343',
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('customer_offers', ['tenant_id' => $tenant->id, 'offer_number' => 'OC-1343']);
    }

    public function test_customer_offer_resource_registers_the_supplier_offers_relation(): void
    {
        $this->assertContains(
            SupplierOffersRelationManager::class,
            CustomerOfferResource::getRelations(),
        );
    }

    public function test_supplier_offers_tab_lists_only_offers_for_the_customer_offer(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        session(['tenant_id' => $tenant->id]);

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $customer = Customer::create(['name' => 'Auchan', 'tenant_id' => $tenant->id]);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'status' => 'draft', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $otherOffer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'status' => 'draft', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);

        $attached = SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'customer_offer_id' => $offer->id,
            'currency' => 'RON', 'status' => 'received', 'source_type' => 'manual', 'received_at' => today(),
        ]);
        $unrelated = SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'customer_offer_id' => $otherOffer->id,
            'currency' => 'RON', 'status' => 'received', 'source_type' => 'manual', 'received_at' => today(),
        ]);

        Livewire::test(SupplierOffersRelationManager::class, [
            'ownerRecord' => $offer,
            'pageClass' => EditCustomerOffer::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$attached])
            ->assertCanNotSeeTableRecords([$unrelated]);
    }

    public function test_modal_shows_average_price_and_max_quantity_without_a_margin_column(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $a = $this->attachSupplierProduct($canonical, 'A SRL', 4.0);   // qty 100
        $b = $this->attachSupplierProduct($canonical, 'B SRL', 6.0);   // qty 100

        $page = app(MarketComparison::class);
        $page->toggleSupplierPriority($canonical->id, $a->id);
        $page->toggleSupplierPriority($canonical->id, $b->id);

        $html = (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page)->render();

        // Average product price (5.00) and the combined-availability max (200.00).
        $this->assertStringContainsString('Average product price', $html);
        $this->assertStringContainsString('5.00', $html);
        $this->assertStringContainsString('Max 200.00', $html);

        // The expandable supplier list shows each supplier's priority.
        $this->assertStringContainsString('A SRL', $html);
        $this->assertStringContainsString('B SRL', $html);

        // Margin editing was moved off this modal: no margin column / sale price.
        $this->assertStringNotContainsString('Sale price', $html);
        $this->assertStringNotContainsString('offerMargins', $html);
    }

    public function test_builder_uses_per_product_margin_over_the_offer_level_margin(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 10.0, supermarketGross: 20.0);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            [
                'customer_id' => $supermarket->id,
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                'margin_value' => 10, // offer-level default
            ],
            $tenant->id,
            margins: [$canonical->id => 25], // per-product override
        );

        // 10 landed + 25% = 12.5, using the per-product margin, not the offer's 10%.
        $this->assertSame('12.5000', $offer->items->first()->sale_price);
    }

    public function test_builder_falls_back_to_offer_margin_when_no_per_product_margin_given(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 10.0, supermarketGross: 20.0);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            [
                'customer_id' => $supermarket->id,
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                'margin_value' => 20,
            ],
            $tenant->id,
            margins: [$canonical->id => ''], // blank => fall back to the offer margin
        );

        $this->assertSame('12.0000', $offer->items->first()->sale_price);
    }

    public function test_margins_seed_from_the_offer_margin_and_preserve_edits(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        $page = app(MarketComparison::class);
        $page->offerMarginValue = 15;
        $page->toggleSupplierPriority($canonical->id, $page->bestSupplierProductId($canonical));

        // Rendering the footer seeds the per-product margin from the offer margin.
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(15, $page->offerMargins[$canonical->id]);

        // A per-product edit survives re-renders.
        $page->offerMargins[$canonical->id] = 40;
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(40, $page->offerMargins[$canonical->id]);

        // Deselecting the product drops its margin entry.
        $page->toggleSupplierPriority($canonical->id, $page->bestSupplierProductId($canonical));
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertArrayNotHasKey($canonical->id, $page->offerMargins);
    }

    public function test_offer_built_through_livewire_honours_the_per_product_margin(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $canonical = $this->canonicalWithPrices(supplierLanded: 10.0, supermarketGross: 20.0);

        Livewire::test(MarketComparison::class)
            ->call('toggleProductSelection', $canonical->id, SupplierProduct::query()->value('id'))
            ->set("offerMargins.{$canonical->id}", 50)
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarket->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                'margin_value' => 10,
            ])
            ->assertHasNoActionErrors();

        // 10 landed + 50% per-product margin = 15.0.
        $this->assertSame('15.0000', CustomerOffer::query()->latest('id')->first()?->items->first()?->sale_price);
    }

    public function test_builder_pins_a_hand_picked_supplier_over_the_cheapest(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $cheapSupplier = Supplier::create(['name' => 'Cheap SRL']);
        $cheapProduct = SupplierProduct::create([
            'producer_id' => $cheapSupplier->id,
            'name' => 'Portocale cheap',
            'status' => 'active',
            'unit_price' => 3.0,
            'currency' => 'RON',
            'quantity_available' => 50,
        ]);
        $canonical->supplierProducts()->attach($cheapProduct);

        $preferredSupplier = Supplier::create(['name' => 'Preferred SRL']);
        $preferredProduct = SupplierProduct::create([
            'producer_id' => $preferredSupplier->id,
            'name' => 'Portocale preferred',
            'status' => 'active',
            'unit_price' => 5.0,
            'currency' => 'RON',
            'quantity_available' => 80,
        ]);
        $canonical->supplierProducts()->attach($preferredProduct);

        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            [
                'customer_id' => $supermarket->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_FIXED,
                'margin_value' => 1,
            ],
            $tenant->id,
            supplierPriorities: [$canonical->id => [$preferredProduct->id]],
        );

        $item = $offer->items->first();
        $this->assertSame($preferredSupplier->id, $item->supplier_id);
        $this->assertSame($preferredProduct->id, $item->supplier_product_id);
        $this->assertSame('5.0000', $item->purchase_price);
        $this->assertSame('6.0000', $item->sale_price);
    }

    public function test_builder_pins_a_hand_picked_supermarket_price_as_the_sale_price(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $supplier = Supplier::create(['name' => 'Ferma SRL']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 4.0,
            'currency' => 'RON',
            'quantity_available' => 100,
        ]);
        $canonical->supplierProducts()->attach($supplierProduct);

        $supermarketProduct = SupermarketProduct::factory()->create(['name' => 'Portocale plasa', 'vat_rate' => 0]);
        $canonical->supermarketProducts()->attach($supermarketProduct);

        $auchan = Customer::create(['name' => 'Auchan store', 'tenant_id' => null]);
        $bestPrice = SupermarketPrice::create([
            'supermarket_id' => $auchan->id,
            'supermarket_product_id' => $supermarketProduct->id,
            'price' => 12.0,
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);
        $pickedPrice = SupermarketPrice::create([
            'supermarket_id' => $auchan->id,
            'supermarket_product_id' => $supermarketProduct->id,
            'price' => 9.0,
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            [
                'customer_id' => $auchan->id,
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ],
            $tenant->id,
            supermarketOverrides: [$canonical->id => $pickedPrice->id],
        );

        // Without the override the sale price would follow the best (12.0) price.
        $this->assertSame('9.0000', $offer->items->first()->sale_price);
    }

    public function test_builder_applies_a_percentage_margin_on_landed_cost(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 10.0, supermarketGross: 20.0);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$canonical]),
            [
                'customer_id' => $supermarket->id,
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_PERCENTAGE,
                'margin_value' => 25,
            ],
            $tenant->id,
        );

        $this->assertSame('12.5000', $offer->items->first()->sale_price);
    }

    public function test_page_resolves_active_tenant_and_lists_global_supermarkets_without_a_session_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        // Global supermarket created before auth, so it stays tenant-less.
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        // Deliberately no session('tenant_id'): a fresh visit must still work.

        $page = app(MarketComparison::class);

        $resolvedTenant = (new \ReflectionMethod($page, 'getActiveTenantId'))->invoke($page);
        $this->assertSame($tenant->id, $resolvedTenant);

        $options = (new \ReflectionMethod($page, 'supermarketOptions'))->invoke($page);
        $this->assertArrayHasKey($supermarket->id, $options);
    }

    public function test_offer_selection_lines_pair_each_product_with_its_chosen_supplier(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $withSupplier = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);
        $withSupplier->update(['country_of_origin' => 'ES']);

        // A selected product with no active supplier must be flagged, not hidden.
        $withoutSupplier = CanonicalProduct::factory()->create(['name' => 'Lămâi']);

        $page = app(MarketComparison::class);
        $lines = (new \ReflectionMethod($page, 'offerSelectionLines'))
            ->invoke($page, new EloquentCollection([$withSupplier, $withoutSupplier]));

        $this->assertCount(2, $lines);

        $this->assertSame('Portocale', $lines[0]['product']);
        $this->assertSame('Spain', $lines[0]['country']);
        $this->assertSame('Ferma Verde SRL', $lines[0]['suppliers'][0]['name']);
        // The modal shows the average supplier product price (unit price), not landed cost.
        $this->assertSame(5.0, $lines[0]['avg_price']);
        $this->assertTrue($lines[0]['has_supplier']);

        $this->assertSame('Lămâi', $lines[1]['product']);
        $this->assertFalse($lines[1]['has_supplier']);
        $this->assertSame([], $lines[1]['suppliers']);
    }

    public function test_pinned_supplier_overrides_the_cheapest_in_the_offer_preview(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $cheap = Supplier::create(['name' => 'Cheap SRL']);
        $cheapProduct = SupplierProduct::create([
            'producer_id' => $cheap->id, 'name' => 'Portocale cheap', 'status' => 'active',
            'unit_price' => 3.0, 'currency' => 'RON', 'quantity_available' => 50,
        ]);
        $canonical->supplierProducts()->attach($cheapProduct);

        $preferred = Supplier::create(['name' => 'Preferred SRL']);
        $preferredProduct = SupplierProduct::create([
            'producer_id' => $preferred->id, 'name' => 'Portocale preferred', 'status' => 'active',
            'unit_price' => 5.0, 'currency' => 'RON', 'quantity_available' => 80,
        ]);
        $canonical->supplierProducts()->attach($preferredProduct);

        $page = app(MarketComparison::class);
        $linesMethod = new \ReflectionMethod($page, 'offerSelectionLines');

        // Default: cheapest supplier.
        $default = $linesMethod->invoke($page, new EloquentCollection([$canonical]));
        $this->assertSame('Cheap SRL', $default[0]['suppliers'][0]['name']);

        // After pinning the pricier supplier, the preview follows the pin (priority #1).
        $page->toggleSupplierPriority($canonical->id, $preferredProduct->id);
        $pinned = $linesMethod->invoke($page, new EloquentCollection([$canonical]));
        $this->assertSame('Preferred SRL', $pinned[0]['suppliers'][0]['name']);
        $this->assertSame(5.0, $pinned[0]['avg_price']);

        // Toggling the same supplier off falls back to the cheapest.
        $page->toggleSupplierPriority($canonical->id, $preferredProduct->id);
        $this->assertArrayNotHasKey($canonical->id, $page->selectedSupplierProductIds);
    }

    public function test_picking_a_supplier_also_selects_the_parent_product(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $cheap = Supplier::create(['name' => 'Cheap SRL']);
        $cheapProduct = SupplierProduct::create([
            'producer_id' => $cheap->id, 'name' => 'Portocale cheap', 'status' => 'active',
            'unit_price' => 3.0, 'currency' => 'RON',
        ]);
        $canonical->supplierProducts()->attach($cheapProduct);

        $preferred = Supplier::create(['name' => 'Preferred SRL']);
        $preferredProduct = SupplierProduct::create([
            'producer_id' => $preferred->id, 'name' => 'Portocale preferred', 'status' => 'active',
            'unit_price' => 5.0, 'currency' => 'RON',
        ]);
        $canonical->supplierProducts()->attach($preferredProduct);

        $page = app(MarketComparison::class);

        // Nothing selected yet.
        $this->assertFalse($page->isProductSelected($canonical->id));

        // Picking a supplier from the breakdown selects the parent product too.
        $page->toggleSupplierPriority($canonical->id, $preferredProduct->id);
        $this->assertTrue($page->isProductSelected($canonical->id));
        $this->assertTrue($page->isSupplierSelected($canonical->id, $preferredProduct->id));
        $this->assertFalse($page->isSupplierSelected($canonical->id, $cheapProduct->id));

        // The parent checkbox alone defaults to the cheapest supplier.
        $page->toggleProductSelection($canonical->id, $page->bestSupplierProductId($canonical));
        $this->assertFalse($page->isProductSelected($canonical->id));
        $page->toggleProductSelection($canonical->id, $page->bestSupplierProductId($canonical));
        $this->assertTrue($page->isSupplierSelected($canonical->id, $cheapProduct->id));
    }

    public function test_offer_uses_the_supplier_picked_in_the_breakdown_through_livewire(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        // Global supermarket must be created before auth so it stays tenant-less.
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $cheap = Supplier::create(['name' => 'Cheap SRL']);
        $cheapProduct = SupplierProduct::create([
            'producer_id' => $cheap->id, 'name' => 'Portocale cheap', 'status' => 'active',
            'unit_price' => 3.0, 'currency' => 'RON',
        ]);
        $canonical->supplierProducts()->attach($cheapProduct);

        $preferred = Supplier::create(['name' => 'Preferred SRL']);
        $preferredProduct = SupplierProduct::create([
            'producer_id' => $preferred->id, 'name' => 'Portocale preferred', 'status' => 'active',
            'unit_price' => 5.0, 'currency' => 'RON',
        ]);
        $canonical->supplierProducts()->attach($preferredProduct);

        $component = Livewire::test(MarketComparison::class)
            ->call('toggleSupplierPriority', $canonical->id, $preferredProduct->id);

        // The popup preview reads from the live component state: it must show the
        // picked supplier, not the cheapest.
        $page = $component->instance();
        $linesMethod = new \ReflectionMethod($page, 'offerSelectionLines');
        $selectedMethod = new \ReflectionMethod($page, 'selectedCanonicalProducts');
        $lines = $linesMethod->invoke($page, $selectedMethod->invoke($page));
        $this->assertSame('Preferred SRL', $lines[0]['suppliers'][0]['name']);

        // The main-row "Best supplier (buy)" badge also follows the pin.
        $formatSupplier = new \ReflectionMethod($page, 'formatSupplier');
        $this->assertStringContainsString('Preferred SRL', $formatSupplier->invoke($page, $canonical));

        $component->callAction('createSupermarketOffer', data: [
            'customer_id' => $supermarket->id,
            'currency' => 'RON',
            'sale_mode' => SupermarketOfferBuilder::SALE_FROM_FIXED,
            'margin_value' => 1,
        ])->assertHasNoActionErrors();

        $item = CustomerOffer::query()->latest('id')->first()?->items->first();
        $this->assertNotNull($item);
        $this->assertSame($preferredProduct->id, $item->supplier_product_id);
        $this->assertSame($preferred->id, $item->supplier_id);
    }

    public function test_table_can_be_sorted_by_product_name_and_category(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $fruits = ProductCategory::create(['tenant_id' => null, 'name' => 'Fruits']);
        $veg = ProductCategory::create(['tenant_id' => null, 'name' => 'Vegetables']);

        $cherry = CanonicalProduct::factory()->create(['name' => 'Cherry', 'product_category_id' => $veg->id]);
        $apple = CanonicalProduct::factory()->create(['name' => 'Apple', 'product_category_id' => $fruits->id]);
        $banana = CanonicalProduct::factory()->create(['name' => 'Banana', 'product_category_id' => $fruits->id]);

        Livewire::test(MarketComparison::class)
            ->sortTable('name')
            ->assertCanSeeTableRecords([$apple, $banana, $cherry], inOrder: true)
            ->sortTable('name', 'desc')
            ->assertCanSeeTableRecords([$cherry, $banana, $apple], inOrder: true)
            ->sortTable('category.name')
            ->assertCanSeeTableRecords([$apple, $banana, $cherry], inOrder: true);
    }

    public function test_toggle_supplier_priority_tracks_click_order_and_reindexes(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $a = $this->attachSupplierProduct($canonical, 'A SRL', 3.0);
        $b = $this->attachSupplierProduct($canonical, 'B SRL', 4.0);
        $c = $this->attachSupplierProduct($canonical, 'C SRL', 5.0);

        $page = app(MarketComparison::class);
        $page->toggleSupplierPriority($canonical->id, $a->id);
        $page->toggleSupplierPriority($canonical->id, $b->id);
        $page->toggleSupplierPriority($canonical->id, $c->id);

        // Clicking suppliers appends them in click order (priority = position).
        $this->assertSame([$a->id, $b->id, $c->id], $page->selectedSupplierProductIds[$canonical->id]);
        $this->assertSame(1, $page->supplierPriority($canonical->id, $a->id));
        $this->assertSame(2, $page->supplierPriority($canonical->id, $b->id));
        $this->assertSame(3, $page->supplierPriority($canonical->id, $c->id));

        // Re-clicking the middle supplier removes it and renumbers the rest.
        $page->toggleSupplierPriority($canonical->id, $b->id);
        $this->assertSame([$a->id, $c->id], $page->selectedSupplierProductIds[$canonical->id]);
        $this->assertNull($page->supplierPriority($canonical->id, $b->id));
        $this->assertSame(2, $page->supplierPriority($canonical->id, $c->id));

        // Removing every supplier drops the product from the offer entirely.
        $page->toggleSupplierPriority($canonical->id, $a->id);
        $page->toggleSupplierPriority($canonical->id, $c->id);
        $this->assertFalse($page->isProductSelected($canonical->id));
        $this->assertArrayNotHasKey($canonical->id, $page->selectedSupplierProductIds);
    }

    public function test_supplier_priority_is_capped_per_product(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $products = [];
        for ($i = 0; $i < 6; $i++) {
            $products[] = $this->attachSupplierProduct($canonical, "S{$i} SRL", 3.0 + $i);
        }

        $page = app(MarketComparison::class);
        foreach ($products as $product) {
            $page->toggleSupplierPriority($canonical->id, $product->id);
        }

        // The 6th click is a no-op: only the first 5 are prioritized.
        $this->assertCount(MarketComparison::MAX_SUPPLIERS_PER_PRODUCT, $page->selectedSupplierProductIds[$canonical->id]);
        $this->assertNull($page->supplierPriority($canonical->id, $products[5]->id));
    }

    public function test_create_offer_attaches_prioritized_suppliers_to_the_offer_line(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        // One canonical with a cheapest supplier (landed 6.0) plus a cheaper
        // second supplier (landed 5.5) so both appear as active candidates.
        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);
        $firstId = (int) SupplierProduct::query()->where('name', 'Portocale Navel')->value('id');
        $second = $this->attachSupplierProduct($canonical, 'Second SRL', 5.5);

        $component = Livewire::test(MarketComparison::class)
            ->call('toggleSupplierPriority', $canonical->id, $second->id)  // priority 1 (buy source)
            ->call('toggleSupplierPriority', $canonical->id, $firstId);    // priority 2

        $component->callAction('createSupermarketOffer', data: [
            'customer_id' => $supermarket->id,
            'currency' => 'RON',
            'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
        ])->assertHasNoActionErrors();

        $offer = CustomerOffer::query()->latest('id')->first();
        $this->assertNotNull($offer);

        $item = $offer->items()->with('suppliers')->first();
        $this->assertNotNull($item);

        $suppliers = $item->suppliers;
        $this->assertCount(2, $suppliers);
        $this->assertSame($second->id, $suppliers[0]->supplier_product_id);
        $this->assertSame(1, $suppliers[0]->priority);
        $this->assertSame($firstId, $suppliers[1]->supplier_product_id);
        $this->assertSame(2, $suppliers[1]->priority);
        $this->assertSame('pending', $suppliers[0]->status);

        // The offer is the single entity: no separate supplier offer is generated.
        $this->assertSame(0, SupplierOffer::count());
    }

    public function test_offer_selection_line_averages_prices_and_sums_available_quantities(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);
        $a = $this->attachSupplierProduct($canonical, 'A SRL', 4.0);   // qty 100
        $b = $this->attachSupplierProduct($canonical, 'B SRL', 6.0);   // qty 100

        $page = app(MarketComparison::class);
        $page->toggleSupplierPriority($canonical->id, $a->id);
        $page->toggleSupplierPriority($canonical->id, $b->id);

        $lines = (new \ReflectionMethod($page, 'offerSelectionLines'))
            ->invoke($page, new EloquentCollection([$canonical]));

        // Average of the chosen suppliers' product prices (not landed cost).
        $this->assertSame(5.0, $lines[0]['avg_price']);
        // Max editable quantity = the suppliers' combined availability.
        $this->assertSame(200.0, $lines[0]['quantity_available']);

        $suppliers = $lines[0]['suppliers'];
        $this->assertCount(2, $suppliers);
        $this->assertSame(1, $suppliers[0]['priority']);
        $this->assertSame('A SRL', $suppliers[0]['name']);
        $this->assertSame(2, $suppliers[1]['priority']);
        $this->assertSame('B SRL', $suppliers[1]['name']);
    }

    public function test_offer_number_fills_from_the_tenant_picked_in_the_modal(): void
    {
        // A super-admin has no default tenant, so the number is blank until a
        // tenant is chosen in the modal, then it fills with that tenant's next number.
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]);
        $user->assignRole('super_admin');
        $this->actingAs($user);

        Customer::create(['name' => 'Auchan', 'tenant_id' => null]);
        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);

        Livewire::test(MarketComparison::class)
            ->call('toggleSupplierPriority', $canonical->id, (int) SupplierProduct::query()->value('id'))
            ->mountAction('createSupermarketOffer')
            ->assertActionDataSet(['offer_number' => null, 'tenant_id' => null])
            ->setActionData(['tenant_id' => $tenant->id])
            ->assertActionDataSet(['offer_number' => 'OC-00001']);
    }

    public function test_prefilled_offer_number_does_not_double_consume_the_sequence(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $canonical = $this->canonicalWithPrices(supplierLanded: 6.0, supermarketGross: 9.0);
        $supplierProductId = (int) SupplierProduct::query()->value('id');

        // Submitting with the prefilled preview number consumes the sequence once.
        Livewire::test(MarketComparison::class)
            ->call('toggleSupplierPriority', $canonical->id, $supplierProductId)
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarket->id,
                'offer_number' => 'OC-00001',
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasNoActionErrors();

        // The next offer advances to OC-00002 (no gap, no collision).
        Livewire::test(MarketComparison::class)
            ->call('toggleSupplierPriority', $canonical->id, $supplierProductId)
            ->callAction('createSupermarketOffer', data: [
                'customer_id' => $supermarket->id,
                'offer_number' => 'OC-00002',
                'currency' => 'RON',
                'sale_mode' => SupermarketOfferBuilder::SALE_FROM_SUPERMARKET,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            ['OC-00001', 'OC-00002'],
            CustomerOffer::query()->orderBy('id')->pluck('offer_number')->all(),
        );
    }

    private function attachSupplierProduct(CanonicalProduct $canonical, string $supplierName, float $unitPrice): SupplierProduct
    {
        $supplier = Supplier::create(['name' => $supplierName]);
        $product = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => $supplierName.' product',
            'status' => 'active',
            'unit_price' => $unitPrice,
            'currency' => 'RON',
            'quantity_available' => 100,
        ]);
        $canonical->supplierProducts()->attach($product);

        return $product;
    }

    public function test_supermarket_breakdown_shows_the_price_excluding_vat(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']));

        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale']);

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        SupplierCostDefault::create(['supplier_id' => $supplier->id, 'transport_cost' => 1]);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale Navel',
            'status' => 'active',
            'unit_price' => 5,
            'currency' => 'RON',
            'quantity_available' => 100,
        ]);
        $canonical->supplierProducts()->attach($supplierProduct);

        $supermarketProduct = SupermarketProduct::factory()->create(['name' => 'Portocale plasa', 'vat_rate' => 19]);
        $canonical->supermarketProducts()->attach($supermarketProduct);

        $auchan = Customer::create(['name' => 'Auchan store', 'tenant_id' => null]);
        SupermarketPrice::create([
            'supermarket_id' => $auchan->id,
            'supermarket_product_id' => $supermarketProduct->id,
            'price' => 11.90,
            'currency' => 'RON',
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);

        // 11.90 gross at 19% VAT resolves to 10.00 excl. VAT; the breakdown must
        // show the net price under the "Price excl. VAT" column, not the gross.
        Livewire::test(MarketComparison::class)
            ->call('toggleBreakdown', $canonical->id)
            ->assertSee('Price excl. VAT')
            ->assertSee('10.00 RON');
    }

    private function canonicalWithPrices(float $supplierLanded, float $supermarketGross): CanonicalProduct
    {
        $canonical = CanonicalProduct::factory()->create(['name' => 'Portocale', 'package_size' => 2, 'package_unit' => 'kg']);

        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        SupplierCostDefault::create([
            'supplier_id' => $supplier->id,
            'transport_cost' => 1,
        ]);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale Navel',
            'status' => 'active',
            'unit_price' => $supplierLanded - 1,
            'currency' => 'RON',
            'quantity_available' => 100,
        ]);
        $canonical->supplierProducts()->attach($supplierProduct);

        $supermarketProduct = SupermarketProduct::factory()->create(['name' => 'Portocale plasa', 'vat_rate' => 0]);
        $canonical->supermarketProducts()->attach($supermarketProduct);

        $auchan = Customer::create(['name' => 'Auchan store', 'tenant_id' => null]);
        SupermarketPrice::create([
            'supermarket_id' => $auchan->id,
            'supermarket_product_id' => $supermarketProduct->id,
            'price' => $supermarketGross,
            'observed_at' => today(),
            'source' => SupermarketPrice::SOURCE_MANUAL,
        ]);

        return $canonical;
    }
}
