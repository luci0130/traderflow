<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Services\CustomerOfferAcceptor;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Reports\Filament\Pages\Revenues;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RevenuesPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Revenue tenant']);
        session(['tenant_id' => $this->tenant->id]);
        Filament::setCurrentPanel('admin');
        $this->actingAsRole('super_admin');
        Filament::setTenant($this->tenant);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $this->tenant->users()->attach($user);
        setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'tenant_id' => null]));
        setPermissionsTeamId($this->tenant->id);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:CustomerOffer', 'guard_name' => 'web']));
        $this->actingAs($user);

        return $user;
    }

    private function offer(?string $orderStatus = null, ?Tenant $tenant = null): CustomerOffer
    {
        $tenant ??= $this->tenant;
        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => Customer::create(['tenant_id' => $tenant->id, 'name' => 'Revenue customer'])->id,
            'currency' => 'EUR', 'status' => 'draft', 'offer_date' => today(),
            'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $item = $offer->items()->create([
            'tenant_id' => $tenant->id, 'product_id' => Product::create(['name' => 'Revenue tomatoes'])->id,
            'quantity' => 100, 'sale_price' => 10, 'purchase_price' => 999, 'tax_rate' => 0,
        ]);
        $item->suppliers()->create([
            'supplier_id' => Supplier::create(['name' => 'Revenue supplier'])->id,
            'priority' => 1, 'unit_price' => 7.5, 'secured_quantity' => 100,
            'transport_cost' => 100, 'currency' => 'EUR', 'include_in_order' => true,
        ]);

        if ($orderStatus !== null) {
            $order = app(CustomerOfferAcceptor::class)->accept($offer);
            $order->update(['status' => $orderStatus]);
        }

        return $offer->fresh();
    }

    public function test_revenues_page_is_registered_and_renders_the_five_requested_totals(): void
    {
        $this->assertTrue(Route::has('filament.admin.pages.revenues'));
        $offer = $this->offer();

        Livewire::test(Revenues::class)->assertSuccessful()->assertCanSeeTableRecords([$offer])
            ->assertTableColumnStateSet('sales_total', 1000, $offer)
            ->assertTableColumnStateSet('product_cost', 750, $offer)
            ->assertTableColumnStateSet('gross_margin', 250, $offer)
            ->assertTableColumnStateSet('transport_cost', 100, $offer)
            ->assertTableColumnStateSet('net_profit', 150, $offer);
    }

    /** @return array<string, array{?string, string}> */
    public static function orderStages(): array
    {
        return [
            'unaccepted offer' => [null, 'Estimated profit'],
            'accepted offer' => ['draft', 'Estimated profit'],
            'confirmed order' => ['confirmed', 'Estimated profit'],
            'preparing order' => ['in_preparation', 'Estimated profit'],
            'delivered order' => ['delivered', 'Realized profit'],
            'invoiced order' => ['invoiced', 'Realized profit'],
            'paid order' => ['paid', 'Realized profit'],
        ];
    }

    #[DataProvider('orderStages')]
    public function test_profit_changes_from_estimated_to_realized_at_finalized_order_states(?string $status, string $expected): void
    {
        $offer = $this->offer($status);
        Livewire::test(Revenues::class)->assertSuccessful()
            ->assertTableColumnStateSet('gain_status', $expected, $offer)
            ->assertTableColumnStateSet('net_profit', 150, $offer);
    }

    public function test_accepting_an_offer_adds_one_sale_without_duplicate_revenue_rows(): void
    {
        $offer = $this->offer();
        app(CustomerOfferAcceptor::class)->accept($offer);
        app(CustomerOfferAcceptor::class)->accept($offer->fresh());
        Livewire::test(Revenues::class)->assertCanSeeTableRecords([$offer])->assertCountTableRecords(1);
    }

    public function test_realized_and_estimated_filters_separate_the_same_calculated_amounts(): void
    {
        $estimated = $this->offer();
        $realized = $this->offer('paid');
        Livewire::test(Revenues::class)->filterTable('gain_status', 'realized')
            ->assertCanSeeTableRecords([$realized])->assertCanNotSeeTableRecords([$estimated]);
        Livewire::test(Revenues::class)->filterTable('gain_status', 'estimated')
            ->assertCanSeeTableRecords([$estimated])->assertCanNotSeeTableRecords([$realized]);
    }

    public function test_rejected_expired_cancelled_offers_and_cancelled_sales_do_not_count_as_income(): void
    {
        $excluded = collect(['rejected', 'expired', 'cancelled'])->map(function (string $status): CustomerOffer {
            $offer = $this->offer();
            $offer->update(['status' => $status]);

            return $offer;
        });
        $excluded->push($this->offer('cancelled'));
        $kept = $this->offer();
        Livewire::test(Revenues::class)->assertCanSeeTableRecords([$kept])->assertCanNotSeeTableRecords($excluded);
    }

    public function test_active_tenant_is_respected_even_for_super_admin(): void
    {
        $offer = $this->offer();
        $otherTenant = Tenant::create(['name' => 'Other tenant']);
        $foreign = $this->offer(tenant: $otherTenant);
        Livewire::test(Revenues::class)->assertCanSeeTableRecords([$offer])->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_sales_agents_with_offer_view_permission_can_access_revenue(): void
    {
        $this->actingAsRole('sales_agent');
        Livewire::test(Revenues::class)->assertSuccessful();
    }

    public function test_purchasing_agents_cannot_access_selling_profit(): void
    {
        $this->actingAsRole('purchasing_agent');
        Livewire::test(Revenues::class)->assertForbidden();
    }

    public function test_revoking_offer_view_permission_prevents_access_on_the_next_request(): void
    {
        $user = $this->actingAsRole('sales_agent');
        $page = Livewire::test(Revenues::class)->assertSuccessful();
        $user->revokePermissionTo('ViewAny:CustomerOffer');
        $page->call('$refresh')->assertForbidden();
    }

    public function test_missing_supplier_prices_display_incomplete_data_instead_of_profit(): void
    {
        $offer = $this->offer();
        $offer->items()->first()->suppliers()->update(['unit_price' => null]);
        Livewire::test(Revenues::class)->assertTableColumnStateSet('net_profit', null, $offer)
            ->assertSee('Incomplete quantities, costs or exchange rates');
    }

    public function test_date_and_currency_filters_limit_the_table(): void
    {
        $offer = $this->offer();
        $older = $this->offer();
        $older->update(['offer_date' => today()->subMonth()]);
        Livewire::test(Revenues::class)->filterTable('offer_date', ['from' => today()->toDateString(), 'until' => today()->toDateString()])
            ->assertCanSeeTableRecords([$offer])->assertCanNotSeeTableRecords([$older]);
        Livewire::test(Revenues::class)->filterTable('currency', 'RON')->assertCanNotSeeTableRecords([$offer, $older]);
    }
}
