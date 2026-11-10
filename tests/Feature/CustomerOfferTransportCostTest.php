<?php

namespace Tests\Feature;

use App\Models\CurrencyExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\ItemsRelationManager;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Models\CustomerOfferItemSupplier;
use App\Modules\CustomerOffers\Services\CustomerOfferAcceptor;
use App\Modules\CustomerOffers\Services\CustomerOfferDuplicator;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerOfferTransportCostTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Transport test']);
        $user = User::factory()->create();
        $this->tenant->users()->attach($user);
        setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]));
        $this->actingAs($user);
        session(['tenant_id' => $this->tenant->id]);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->tenant);
        setPermissionsTeamId($this->tenant->id);
    }

    /** @return array{CustomerOffer, CustomerOfferItem, CustomerOfferItemSupplier} */
    private function offer(?float $quantity = 20, ?float $price = 5): array
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Customer']);
        $offer = CustomerOffer::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'currency' => 'RON', 'status' => 'draft', 'offer_date' => today(),
            'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $product = Product::create(['name' => 'Tomatoes', 'status' => 'active']);
        $item = $offer->items()->create([
            'tenant_id' => $this->tenant->id, 'product_id' => $product->id,
            'quantity' => 100, 'sale_price' => 10, 'purchase_price' => 0, 'tax_rate' => 0,
        ]);
        $supplier = Supplier::create(['name' => 'Transport supplier']);
        $source = $item->suppliers()->create([
            'supplier_id' => $supplier->id, 'priority' => 1, 'unit_price' => $price,
            'secured_quantity' => $quantity, 'currency' => 'RON',
        ]);

        return [$offer, $item, $source];
    }

    private function board(CustomerOffer $offer): Testable
    {
        return Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class]);
    }

    public function test_transport_is_saved_per_product_supplier_row_and_total_and_margin_recalculate(): void
    {
        [$offer, $item, $source] = $this->offer();
        $other = $item->suppliers()->create([
            'supplier_id' => Supplier::create(['name' => 'Other supplier'])->id,
            'priority' => 2, 'unit_price' => 8, 'currency' => 'RON',
        ]);

        $component = $this->board($offer)->call('saveSourcing', $source->id, 'transport_cost', 10)->assertHasNoErrors();
        $this->assertSame('10.0000', $source->fresh()->transport_cost);
        $this->assertSame('0.0000', $other->fresh()->transport_cost);
        $this->assertSame(110.0, $source->fresh()->total_cost);
        $this->assertSame('5.5000', $item->fresh()->purchase_price);
        $this->assertSame('81.8182', $item->fresh()->margin_percent);
        $component->assertSee('110.00 RON');

        $component->call('saveSourcing', $source->id, 'secured_quantity', 40)->assertHasNoErrors();
        $this->assertSame(210.0, $source->fresh()->total_cost);
        $this->assertSame('5.2500', $item->fresh()->purchase_price);
        $this->assertSame('10.0000', $source->fresh()->transport_cost);
    }

    public function test_each_product_from_the_same_supplier_can_have_a_different_transport_cost(): void
    {
        [$offer, $item, $first] = $this->offer();
        $secondItem = $offer->items()->create([
            'tenant_id' => $this->tenant->id, 'product_id' => Product::create(['name' => 'Cucumbers'])->id,
            'quantity' => 10, 'purchase_price' => 0, 'sale_price' => 10, 'tax_rate' => 0,
        ]);
        $second = $secondItem->suppliers()->create([
            'supplier_id' => $first->supplier_id, 'priority' => 1, 'unit_price' => 3, 'secured_quantity' => 10, 'currency' => 'RON',
        ]);

        $this->board($offer)->call('saveSourcing', $first->id, 'transport_cost', 10)
            ->call('saveSourcing', $second->id, 'transport_cost', 7)->assertHasNoErrors();

        $this->assertSame(110.0, $first->fresh()->total_cost);
        $this->assertSame(37.0, $second->fresh()->total_cost);
        $this->assertSame('10.0000', $first->fresh()->transport_cost);
        $this->assertSame('7.0000', $second->fresh()->transport_cost);
    }

    public function test_total_defaults_to_product_price_times_quantity_without_transport(): void
    {
        [, , $source] = $this->offer();
        $this->assertSame('0.0000', $source->fresh()->transport_cost);
        $this->assertSame(100.0, $source->total_cost);
    }

    public function test_missing_and_zero_secured_quantities_do_not_divide_by_zero_or_discard_transport(): void
    {
        [$offer, , $source] = $this->offer(null);
        $component = $this->board($offer)->call('saveSourcing', $source->id, 'transport_cost', 10)->assertHasNoErrors();
        $this->assertNull($source->fresh()->total_cost);
        $this->assertNull($source->fresh()->effective_unit_cost);

        $component->call('saveSourcing', $source->id, 'secured_quantity', 0)->assertHasNoErrors();
        $this->assertSame(10.0, $source->fresh()->total_cost);
        $this->assertNull($source->fresh()->effective_unit_cost);
        $this->assertSame('10.0000', $source->fresh()->transport_cost);
    }

    public function test_clearing_transport_restores_zero_and_clearing_quantity_restores_an_unknown_total(): void
    {
        [$offer, , $source] = $this->offer();
        $component = $this->board($offer)->call('saveSourcing', $source->id, 'transport_cost', 10)
            ->call('saveSourcing', $source->id, 'transport_cost', '')->assertHasNoErrors();
        $this->assertSame('0.0000', $source->fresh()->transport_cost);
        $this->assertSame(100.0, $source->fresh()->total_cost);

        $component->call('saveSourcing', $source->id, 'secured_quantity', '')->assertHasNoErrors();
        $this->assertNull($source->fresh()->secured_quantity);
        $this->assertNull($source->fresh()->total_cost);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidValues(): array
    {
        return [
            'negative transport' => ['transport_cost', -1],
            'non-numeric transport' => ['transport_cost', 'invalid'],
            'oversized transport' => ['transport_cost', 100000000000],
            'negative quantity' => ['secured_quantity', -1],
            'non-numeric quantity' => ['secured_quantity', 'invalid'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_sourcing_values_are_rejected_without_saving(string $field, mixed $value): void
    {
        [$offer, , $source] = $this->offer();
        $this->board($offer)->call('saveSourcing', $source->id, $field, $value)
            ->assertHasErrors('sourcing.'.$source->id.'.'.$field);
        $this->assertSame('0.0000', $source->fresh()->transport_cost);
        $this->assertSame('20.0000', $source->fresh()->secured_quantity);
    }

    public function test_only_included_suppliers_contribute_their_transport_to_the_average(): void
    {
        [$offer, $item, $first] = $this->offer();
        $second = $item->suppliers()->create([
            'supplier_id' => Supplier::create(['name' => 'Second supplier'])->id,
            'priority' => 2, 'unit_price' => 6, 'secured_quantity' => 10, 'currency' => 'RON',
        ]);
        $component = $this->board($offer)->call('saveSourcing', $first->id, 'transport_cost', 10)
            ->call('saveSourcing', $second->id, 'transport_cost', 20)->assertHasNoErrors();
        $this->assertSame('6.3333', $item->fresh()->purchase_price);

        $component->call('saveInclude', $second->id, false)->assertHasNoErrors();
        $this->assertSame('5.5000', $item->fresh()->purchase_price);
    }

    public function test_average_includes_transport_and_converts_into_the_offer_currency(): void
    {
        CurrencyExchangeRate::factory()->create(['rate' => 5]);
        [$offer, $item, $source] = $this->offer(10, 2);
        $source->update(['currency' => 'EUR']);
        $this->board($offer)->call('saveSourcing', $source->id, 'transport_cost', 1)->assertHasNoErrors();

        $this->assertSame(21.0, $source->fresh()->total_cost);
        $this->assertSame('10.5000', $item->fresh()->purchase_price);
    }

    public function test_supplier_order_uses_the_total_once_even_when_unit_cost_repeats_decimals(): void
    {
        [$offer, , $source] = $this->offer(3, 10);
        $this->board($offer)->call('saveSourcing', $source->id, 'transport_cost', 1)->assertHasNoErrors();
        app(CustomerOfferAcceptor::class)->accept($offer->fresh());

        $order = SupplierOrder::query()->where('customer_offer_id', $offer->id)->sole();
        $this->assertSame('31.0000', $order->total);
        $this->assertSame('31.0000', $order->items()->sole()->line_total);
        $this->assertSame('10.3333', $order->items()->sole()->purchase_price);
        $this->assertSame('1.0000', $source->fresh()->transport_cost);
    }

    public function test_duplicate_offer_preserves_per_supplier_transport(): void
    {
        [$offer, , $source] = $this->offer();
        $this->board($offer)->call('saveSourcing', $source->id, 'transport_cost', 12.5)->assertHasNoErrors();
        $copy = app(CustomerOfferDuplicator::class)->duplicate($offer);
        $copiedSource = $copy->items()->sole()->suppliers()->sole();

        $this->assertSame('12.5000', $copiedSource->transport_cost);
        $this->assertSame(112.5, $copiedSource->total_cost);
    }

    public function test_other_offer_rows_and_computed_total_cannot_be_modified(): void
    {
        [$offer, , $source] = $this->offer();
        [, , $foreign] = $this->offer();
        $this->board($offer)->call('saveSourcing', $foreign->id, 'transport_cost', 100)
            ->call('saveSourcing', $source->id, 'total_cost', 999)
            ->call('saveSourcing', $source->id, 'landed_cost', 999)->assertHasNoErrors();

        $this->assertSame('0.0000', $foreign->fresh()->transport_cost);
        $this->assertSame(100.0, $source->fresh()->total_cost);
    }

    public function test_sales_agents_cannot_modify_transport(): void
    {
        [$offer, , $source] = $this->offer();
        $user = User::factory()->create();
        setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'sales_agent', 'guard_name' => 'web', 'tenant_id' => null]));
        setPermissionsTeamId($this->tenant->id);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'Update:CustomerOffer', 'guard_name' => 'web']));
        $this->actingAs($user);

        $this->board($offer)->call('saveSourcing', $source->id, 'transport_cost', 50);
        $this->assertSame('0.0000', $source->fresh()->transport_cost);
    }
}
