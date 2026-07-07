<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\ItemsRelationManager;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferConverter;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class CustomerOfferSourcingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Tenant A']);
    }

    /**
     * @return array{CustomerOffer, CustomerOfferItem, int, int}
     */
    private function offerWithTwoSuppliers(): array
    {
        $customer = Customer::create(['name' => 'Auchan', 'tenant_id' => $this->tenant->id]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Roșii', 'status' => 'active']);

        $offer = CustomerOffer::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'status' => 'draft', 'offer_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);

        $item = CustomerOfferItem::create([
            'tenant_id' => $this->tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $product->id,
            'quantity' => 90, 'purchase_price' => 0, 'sale_price' => 10, 'tax_rate' => 0,
        ]);

        $first = Supplier::create(['name' => 'Prima SRL']);
        $second = Supplier::create(['name' => 'Doua SRL']);

        $rowA = $item->suppliers()->create(['supplier_id' => $first->id, 'priority' => 1, 'unit_price' => 3, 'currency' => 'RON', 'quantity_available' => 100, 'status' => 'pending']);
        $rowB = $item->suppliers()->create(['supplier_id' => $second->id, 'priority' => 2, 'unit_price' => 4, 'currency' => 'RON', 'quantity_available' => 100, 'status' => 'pending']);

        return [$offer, $item, $rowA->id, $rowB->id];
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $this->tenant->users()->attach($user);
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'tenant_id' => null]);
        $user->assignRole($role);
        $this->actingAs($user);
        session(['tenant_id' => $this->tenant->id]);
        Filament::setTenant($this->tenant);

        return $user;
    }

    public function test_board_renders_products_and_suppliers_groupings(): void
    {
        $this->actingAsRole('purchasing_agent');
        [$offer] = $this->offerWithTwoSuppliers();

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class])
            ->assertSuccessful()
            ->assertSee('Roșii')
            ->assertSee('Prima SRL')
            ->assertSee('Doua SRL');
    }

    public function test_filling_landed_cost_and_secured_quantity_rolls_up_to_the_offer_line(): void
    {
        $this->actingAsRole('purchasing_agent');
        [$offer, $item, $rowAId, $rowBId] = $this->offerWithTwoSuppliers();

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class])
            ->call('saveSourcing', $rowAId, 'secured_quantity', 60)
            ->call('saveSourcing', $rowAId, 'landed_cost', 5)
            ->call('saveSourcing', $rowBId, 'secured_quantity', 40)
            ->call('saveSourcing', $rowBId, 'landed_cost', 6);

        // Per-supplier entries persist.
        $this->assertSame('60.0000', (string) $item->suppliers()->where('priority', 1)->value('secured_quantity'));

        // The line's purchase price becomes the secured-weighted average landed
        // cost (Landed Cost Mediu) = (60*5 + 40*6) / 100 = 5.40. The desired
        // quantity is left untouched.
        $item->refresh();
        $this->assertSame('90.0000', (string) $item->quantity);
        $this->assertSame('5.4000', (string) $item->purchase_price);
    }

    public function test_purchase_price_uses_only_suppliers_kept_in_the_order(): void
    {
        // A super-admin can both fill sourcing and toggle inclusion.
        $this->actingAsRole('super_admin');
        [$offer, $item, $rowAId, $rowBId] = $this->offerWithTwoSuppliers();

        $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class])
            ->call('saveSourcing', $rowAId, 'secured_quantity', 60)
            ->call('saveSourcing', $rowAId, 'landed_cost', 5)
            ->call('saveSourcing', $rowBId, 'secured_quantity', 40)
            ->call('saveSourcing', $rowBId, 'landed_cost', 6);

        // Both suppliers kept: weighted average = (60*5 + 40*6) / 100 = 5.40.
        $this->assertSame('5.4000', (string) $item->fresh()->purchase_price);

        // Excluding supplier B leaves only A: purchase price becomes 5.00.
        $component->call('saveInclude', $rowBId, false);
        $this->assertSame('5.0000', (string) $item->fresh()->purchase_price);
    }

    public function test_seller_can_set_sale_price_and_margin_inline(): void
    {
        $this->actingAsRole('sales_agent');
        [$offer, $item] = $this->offerWithTwoSuppliers();
        $item->update(['purchase_price' => 5]);

        $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class]);

        // Setting the sale price derives the margin percent.
        $component->call('saveSale', $item->id, 'sale_price', 8);
        $item->refresh();
        $this->assertSame('8.0000', (string) $item->sale_price);
        $this->assertSame('60.0000', (string) $item->margin_percent);

        // Setting the margin percent recomputes the sale price from the landed cost.
        $component->call('saveSale', $item->id, 'margin_percent', 100);
        $item->refresh();
        $this->assertSame('10.0000', (string) $item->sale_price);
    }

    public function test_unticking_all_of_a_lines_suppliers_drops_it_from_the_order(): void
    {
        $this->actingAsRole('sales_agent');
        [$offer, $itemA, $rowAId, $rowBId] = $this->offerWithTwoSuppliers();

        $keep = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Castraveți', 'status' => 'active']);
        $itemB = CustomerOfferItem::create([
            'tenant_id' => $this->tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $keep->id,
            'quantity' => 10, 'purchase_price' => 2, 'sale_price' => 5, 'tax_rate' => 0,
        ]);
        $itemB->suppliers()->create(['supplier_id' => Supplier::create(['name' => 'Terta SRL'])->id, 'priority' => 1, 'unit_price' => 2, 'currency' => 'RON', 'status' => 'pending']);

        // Untick both suppliers of the first line via the board (per product→supplier).
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class])
            ->call('saveInclude', $rowAId, false)
            ->call('saveInclude', $rowBId, false);

        // The first line has no kept supplier => excluded; the second keeps its supplier.
        $this->assertFalse($itemA->fresh()->isIncludedInOrder());
        $this->assertTrue($itemB->fresh()->isIncludedInOrder());

        // The converter builds the sales order only from the kept lines.
        $offer->update(['status' => 'accepted']);
        $salesOrder = app(CustomerOfferConverter::class)->convert($offer->fresh());

        $this->assertCount(1, $salesOrder->items);
        $this->assertSame($keep->id, $salesOrder->items->first()->product_id);
    }

    public function test_a_line_stays_in_the_order_while_at_least_one_supplier_is_kept(): void
    {
        $this->actingAsRole('sales_agent');
        [$offer, $itemA, $rowAId] = $this->offerWithTwoSuppliers();

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class])
            ->call('saveInclude', $rowAId, false);

        // One supplier still kept, so the line remains in the order.
        $this->assertTrue($itemA->fresh()->isIncludedInOrder());
    }

    public function test_purchasing_agent_opens_the_offer_without_the_sell_side(): void
    {
        $user = $this->actingAsRole('purchasing_agent');
        setPermissionsTeamId($this->tenant->getKey());
        $user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'View:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:CustomerOffer', 'guard_name' => 'web']),
        );

        [$offer] = $this->offerWithTwoSuppliers();

        $this->assertFalse(CustomerOfferResource::showsSellSide());

        // The purchasing agent can open the same offer, but the sell-side totals
        // (e.g. the "Subtotal" field) are hidden.
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSee('Subtotal');
    }

    public function test_sales_agent_sees_the_sell_side(): void
    {
        $this->actingAsRole('sales_agent');
        $this->offerWithTwoSuppliers();

        $this->assertTrue(CustomerOfferResource::showsSellSide());
    }
}
