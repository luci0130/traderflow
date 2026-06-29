<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Reports\Filament\Pages\SupermarketMarginReport;
use App\Modules\Reports\Filament\Widgets\SupermarketMarginChart;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class SupermarketMarginChartTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $this->tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
        session(['tenant_id' => $this->tenant->id]);

        setPermissionsTeamId($this->tenant->getKey());
        Permission::firstOrCreate(['name' => 'ViewAny:CustomerOffer', 'guard_name' => 'web']);
        // The margin report page is restricted to super admins.
        $user->assignRole(
            Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]),
        );

        $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Mere']);
    }

    private function supermarket(string $name): Customer
    {
        // Supermarkets are global (tenant-less) customers. The BelongsToTenant
        // creating hook stamps the active tenant, so null it out explicitly to
        // mirror how supermarkets exist in global mode.
        $supermarket = Customer::create(['tenant_id' => null, 'name' => $name]);
        DB::table('customers')->where('id', $supermarket->id)->update(['tenant_id' => null]);
        $supermarket->tenant_id = null;

        return $supermarket;
    }

    /**
     * The CustomerOfferItem observer derives margin_value / margin_percent from the
     * purchase and sale prices, so the margin is driven through the sale price here:
     * with purchase 1.00, sale (1 + margin%) gives that percentage and (sale - 1) the
     * per-unit value.
     */
    private function offerWithItem(Customer $supermarket, string $status, float $salePrice, ?string $updatedAt = null): CustomerOffer
    {
        $offer = CustomerOffer::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $supermarket->id,
            'currency' => 'EUR',
            'status' => $status,
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ]);

        CustomerOfferItem::create([
            'tenant_id' => $this->tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
            'purchase_price' => 1.00,
            'sale_price' => $salePrice,
        ]);

        if ($updatedAt !== null) {
            DB::table('customer_offers')->where('id', $offer->id)->update(['updated_at' => $updatedAt]);
        }

        return $offer;
    }

    private function dataFor(array $filters): array
    {
        $widget = new SupermarketMarginChart;
        $widget->filters = $filters;

        return (new ReflectionMethod($widget, 'getData'))->invoke($widget);
    }

    public function test_the_report_page_is_registered_as_a_filament_route(): void
    {
        $this->assertTrue(Route::has('filament.admin.pages.reports.supermarket-margins'));
    }

    public function test_without_a_product_the_chart_has_no_datasets(): void
    {
        $data = $this->dataFor(['product_id' => null]);

        $this->assertSame([], $data['datasets']);
    }

    public function test_accepted_and_rejected_offers_become_green_and_red_datasets(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $auchan = $this->supermarket('Auchan');

        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20); // 20%
        $this->offerWithItem($auchan, 'rejected', salePrice: 1.35); // 35%

        $data = $this->dataFor([
            'product_id' => $this->product->id,
            'supermarket_id' => null,
            'margin_type' => 'percent',
        ]);

        [$accepted, $rejected] = $data['datasets'];

        $this->assertSame('#16a34a', $accepted['backgroundColor']);
        $this->assertTrue($accepted['showLine']);
        $this->assertEquals(20.0, $accepted['data'][0]['y']);
        $this->assertSame(['Kaufland'], $accepted['pointLabels']);

        $this->assertSame('#dc2626', $rejected['backgroundColor']);
        $this->assertFalse($rejected['showLine']);
        $this->assertEquals(35.0, $rejected['data'][0]['y']);
        $this->assertSame(['Auchan'], $rejected['pointLabels']);
    }

    public function test_fixed_margin_type_plots_the_per_unit_margin_value(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.45); // +0.45 per unit

        $data = $this->dataFor([
            'product_id' => $this->product->id,
            'margin_type' => 'fixed',
        ]);

        $this->assertEqualsWithDelta(0.45, $data['datasets'][0]['data'][0]['y'], 0.0001);
    }

    public function test_margin_unit_is_percent_in_percentage_mode_and_currency_in_fixed_mode(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20); // currency EUR

        $percent = $this->dataFor(['product_id' => $this->product->id, 'margin_type' => 'percent']);
        $this->assertSame('%', $percent['marginUnit']);

        $fixed = $this->dataFor(['product_id' => $this->product->id, 'margin_type' => 'fixed']);
        $this->assertSame(' EUR', $fixed['marginUnit']);
    }

    public function test_fixed_margin_unit_is_blank_when_offers_span_multiple_currencies(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $auchan = $this->supermarket('Auchan');

        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20); // EUR
        $ron = $this->offerWithItem($auchan, 'accepted', salePrice: 1.25);
        DB::table('customer_offers')->where('id', $ron->id)->update(['currency' => 'RON']);

        $fixed = $this->dataFor(['product_id' => $this->product->id, 'margin_type' => 'fixed']);

        $this->assertSame('', $fixed['marginUnit']);
    }

    public function test_accepted_points_are_ordered_chronologically_for_the_line(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $auchan = $this->supermarket('Auchan');

        // Created out of order: the later decision is inserted first.
        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20, updatedAt: '2026-03-10 10:00:00'); // 20%
        $this->offerWithItem($auchan, 'accepted', salePrice: 1.25, updatedAt: '2026-01-05 10:00:00'); // 25%

        $data = $this->dataFor([
            'product_id' => $this->product->id,
            'margin_type' => 'percent',
        ]);

        $accepted = $data['datasets'][0];

        // Earliest first so the connecting line runs left-to-right.
        $this->assertSame(['Auchan', 'Kaufland'], $accepted['pointLabels']);
        $this->assertEquals(25.0, $accepted['data'][0]['y']);
        $this->assertEquals(20.0, $accepted['data'][1]['y']);
    }

    public function test_supermarket_filter_limits_points_to_one_supermarket(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $auchan = $this->supermarket('Auchan');

        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20);
        $this->offerWithItem($auchan, 'accepted', salePrice: 1.25);

        $data = $this->dataFor([
            'product_id' => $this->product->id,
            'supermarket_id' => $kaufland->id,
            'margin_type' => 'percent',
        ]);

        $this->assertSame(['Kaufland'], $data['datasets'][0]['pointLabels']);
    }

    public function test_chart_options_contain_no_double_quotes_so_the_x_data_attribute_is_not_broken(): void
    {
        // getOptions() returns RawJs emitted verbatim into a double-quoted HTML
        // attribute; any double quote would close the attribute and leak the JS as
        // visible page text.
        foreach (['percent', 'fixed'] as $marginType) {
            $widget = new SupermarketMarginChart;
            $widget->filters = ['product_id' => $this->product->id, 'margin_type' => $marginType];

            $options = (string) (new ReflectionMethod($widget, 'getOptions'))->invoke($widget);

            $this->assertStringNotContainsString('"', $options);
        }
    }

    public function test_time_bounds_span_the_first_and_last_decision_in_view(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $auchan = $this->supermarket('Auchan');

        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20, updatedAt: '2026-02-10 10:00:00');
        $this->offerWithItem($auchan, 'rejected', salePrice: 1.30, updatedAt: '2026-05-20 10:00:00');

        $data = $this->dataFor(['product_id' => $this->product->id, 'period' => 'all']);

        $this->assertSame('2026-02-10T10:00:00+00:00', $data['xMin']);
        $this->assertSame('2026-05-20T10:00:00+00:00', $data['xMax']);
    }

    public function test_period_filter_limits_offers_to_the_recent_window(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $auchan = $this->supermarket('Auchan');

        $recent = now()->subDays(10)->format('Y-m-d H:i:s');
        $old = now()->subMonths(8)->format('Y-m-d H:i:s');

        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20, updatedAt: $recent);
        $this->offerWithItem($auchan, 'accepted', salePrice: 1.25, updatedAt: $old);

        $lastMonth = $this->dataFor(['product_id' => $this->product->id, 'period' => 'last_month']);
        $this->assertSame(['Kaufland'], $lastMonth['datasets'][0]['pointLabels']);

        $allTime = $this->dataFor(['product_id' => $this->product->id, 'period' => 'all']);
        $this->assertCount(2, $allTime['datasets'][0]['data']);
    }

    public function test_product_query_param_preselects_the_product_filter(): void
    {
        // The product profit report links here with `?product=ID`; the chart must
        // default its product filter from that so the deep link lands ready to read.
        Livewire::withQueryParams(['product' => $this->product->id])
            ->test(SupermarketMarginChart::class)
            ->assertSet('filters.product_id', $this->product->id);
    }

    public function test_the_report_page_renders_with_the_chart_widget(): void
    {
        $kaufland = $this->supermarket('Kaufland');
        $this->offerWithItem($kaufland, 'accepted', salePrice: 1.20);

        Livewire::test(SupermarketMarginReport::class)
            ->assertSuccessful();

        Livewire::test(SupermarketMarginChart::class)
            ->assertSuccessful();
    }
}
