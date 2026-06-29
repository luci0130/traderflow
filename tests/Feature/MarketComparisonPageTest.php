<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Filament\Pages\MarketComparison;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Models\SupplierCostDefault;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\MarketComparison\Services\MarketComparisonRowAssembler;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\Product;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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
        $page->selectSupplier($canonical->id, $page->bestSupplierProductId($canonical));

        // Rendering the modal footer seeds the default quantity (max available).
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(100.0, $page->offerQuantities[$canonical->id]);

        // A user edit is preserved across re-renders.
        $page->offerQuantities[$canonical->id] = 25;
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(25, $page->offerQuantities[$canonical->id]);

        // Deselecting the product drops its quantity entry.
        $page->selectSupplier($canonical->id, $page->bestSupplierProductId($canonical));
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertArrayNotHasKey($canonical->id, $page->offerQuantities);
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

    public function test_builder_creates_one_supplier_offer_per_supplier_and_links_the_items(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        // Two products from the same supplier, one from a second supplier.
        $supplierOne = Supplier::create(['name' => 'Ferma Unu SRL']);
        $supplierTwo = Supplier::create(['name' => 'Ferma Doi SRL']);

        $apple = CanonicalProduct::factory()->create(['name' => 'Mere']);
        $pear = CanonicalProduct::factory()->create(['name' => 'Pere']);
        $plum = CanonicalProduct::factory()->create(['name' => 'Prune']);

        $appleProduct = SupplierProduct::create([
            'producer_id' => $supplierOne->id, 'name' => 'Mere', 'status' => 'active',
            'unit_price' => 4.0, 'currency' => 'RON', 'quantity_available' => 100,
        ]);
        $pearProduct = SupplierProduct::create([
            'producer_id' => $supplierOne->id, 'name' => 'Pere', 'status' => 'active',
            'unit_price' => 5.0, 'currency' => 'RON', 'quantity_available' => 120,
        ]);
        $plumProduct = SupplierProduct::create([
            'producer_id' => $supplierTwo->id, 'name' => 'Prune', 'status' => 'active',
            'unit_price' => 6.0, 'currency' => 'RON', 'quantity_available' => 80,
        ]);
        $apple->supplierProducts()->attach($appleProduct);
        $pear->supplierProducts()->attach($pearProduct);
        $plum->supplierProducts()->attach($plumProduct);

        $supermarket = Customer::create(['name' => 'Auchan', 'tenant_id' => null]);

        $offer = app(SupermarketOfferBuilder::class)->build(
            new EloquentCollection([$apple, $pear, $plum]),
            ['customer_id' => $supermarket->id, 'currency' => 'RON', 'sale_mode' => SupermarketOfferBuilder::SALE_FROM_FIXED, 'margin_value' => 1],
            $tenant->id,
            quantities: [$apple->id => 30],
        );

        // Three products from two suppliers => exactly two supplier offers.
        $supplierOffers = \App\Modules\SupplierOffers\Models\SupplierOffer::query()->get();
        $this->assertCount(2, $supplierOffers);
        $this->assertEqualsCanonicalizing(
            [$supplierOne->id, $supplierTwo->id],
            $supplierOffers->pluck('supplier_id')->all(),
        );

        // Every supplier offer is attached to the customer offer.
        $this->assertTrue($supplierOffers->every(fn ($supplierOffer): bool => $supplierOffer->customer_offer_id === $offer->id));
        $this->assertCount(2, $offer->supplierOffers);

        // Supplier one's offer groups both of its products.
        $offerOne = $supplierOffers->firstWhere('supplier_id', $supplierOne->id);
        $this->assertCount(2, $offerOne->items);
        $this->assertSame('received', $offerOne->status);

        // Every customer offer item is linked back to a supplier offer item, and
        // the supplier offer item carries the same (edited) quantity and the raw
        // supplier unit price.
        $appleItem = $offer->items->firstWhere('product.name', 'Mere');
        $this->assertNotNull($appleItem->supplier_offer_item_id);
        $supplierItem = $appleItem->supplierOfferItem;
        $this->assertSame('30.0000', $supplierItem->quantity);
        $this->assertSame('4.0000', $supplierItem->purchase_price);
        $this->assertSame($supplierOne->id, $supplierItem->supplierOffer->supplier_id);
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
            // The number is left blank so the tenant sequence fills it on save.
            ->assertActionDataSet([
                'offer_number' => null,
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
            \App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\SupplierOffersRelationManager::class,
            \App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource::getRelations(),
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

        $attached = \App\Modules\SupplierOffers\Models\SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'customer_offer_id' => $offer->id,
            'currency' => 'RON', 'status' => 'received', 'source_type' => 'manual', 'received_at' => today(),
        ]);
        $unrelated = \App\Modules\SupplierOffers\Models\SupplierOffer::create([
            'tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'customer_offer_id' => $otherOffer->id,
            'currency' => 'RON', 'status' => 'received', 'source_type' => 'manual', 'received_at' => today(),
        ]);

        Livewire::test(\App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\SupplierOffersRelationManager::class, [
            'ownerRecord' => $offer,
            'pageClass' => \App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$attached])
            ->assertCanNotSeeTableRecords([$unrelated]);
    }

    public function test_modal_renders_a_sale_price_computed_from_the_per_product_margin(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());

        $canonical = $this->canonicalWithPrices(supplierLanded: 10.0, supermarketGross: 20.0);

        $page = app(MarketComparison::class);
        $page->offerSaleMode = SupermarketOfferBuilder::SALE_FROM_PERCENTAGE;
        $page->selectSupplier($canonical->id, $page->bestSupplierProductId($canonical));
        $page->offerMargins = [$canonical->id => 25];

        $html = (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page)->render();

        // 10 landed + 25% = 12.50, shown as the sale price under the margin input.
        $this->assertStringContainsString('Sale price', $html);
        $this->assertStringContainsString('12.50', $html);

        // The margin input is live so the sale price recomputes as the user types.
        $this->assertStringContainsString('wire:model.live.debounce.400ms="offerMargins.'.$canonical->id.'"', $html);

        // Fixed-amount mode adds the margin to the landed cost instead: 10 + 25 = 35.00.
        $page->offerSaleMode = SupermarketOfferBuilder::SALE_FROM_FIXED;
        $fixedHtml = (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page)->render();
        $this->assertStringContainsString('35.00', $fixedHtml);
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
        $page->selectSupplier($canonical->id, $page->bestSupplierProductId($canonical));

        // Rendering the footer seeds the per-product margin from the offer margin.
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(15, $page->offerMargins[$canonical->id]);

        // A per-product edit survives re-renders.
        $page->offerMargins[$canonical->id] = 40;
        (new \ReflectionMethod($page, 'offerSelectionFooter'))->invoke($page);
        $this->assertSame(40, $page->offerMargins[$canonical->id]);

        // Deselecting the product drops its margin entry.
        $page->selectSupplier($canonical->id, $page->bestSupplierProductId($canonical));
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
            supplierOverrides: [$canonical->id => $preferredProduct->id],
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
        $this->assertSame('Ferma Verde SRL', $lines[0]['supplier']);
        $this->assertSame(6.0, $lines[0]['landed_cost']);
        $this->assertTrue($lines[0]['has_supplier']);

        $this->assertSame('Lămâi', $lines[1]['product']);
        $this->assertFalse($lines[1]['has_supplier']);
        $this->assertNull($lines[1]['supplier']);
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
        $this->assertSame('Cheap SRL', $default[0]['supplier']);

        // After pinning the pricier supplier, the preview follows the pin.
        $page->selectSupplier($canonical->id, $preferredProduct->id);
        $pinned = $linesMethod->invoke($page, new EloquentCollection([$canonical]));
        $this->assertSame('Preferred SRL', $pinned[0]['supplier']);
        $this->assertSame(5.0, $pinned[0]['landed_cost']);

        // Toggling the same supplier off falls back to the cheapest.
        $page->selectSupplier($canonical->id, $preferredProduct->id);
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
        $page->selectSupplier($canonical->id, $preferredProduct->id);
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
            ->call('selectSupplier', $canonical->id, $preferredProduct->id);

        // The popup preview reads from the live component state: it must show the
        // picked supplier, not the cheapest.
        $page = $component->instance();
        $linesMethod = new \ReflectionMethod($page, 'offerSelectionLines');
        $selectedMethod = new \ReflectionMethod($page, 'selectedCanonicalProducts');
        $lines = $linesMethod->invoke($page, $selectedMethod->invoke($page));
        $this->assertSame('Preferred SRL', $lines[0]['supplier']);

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
