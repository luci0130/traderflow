<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Reports\Filament\Pages\SupermarketProductMarginReport;
use App\Modules\Reports\Filament\Widgets\SupermarketProductMarginChart;
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

class SupermarketProductMarginChartTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $supermarket;

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

        $this->supermarket = $this->createSupermarket('Kaufland');
    }

    private function createSupermarket(string $name): Customer
    {
        $supermarket = Customer::create(['tenant_id' => null, 'name' => $name]);
        DB::table('customers')->where('id', $supermarket->id)->update(['tenant_id' => null]);
        $supermarket->tenant_id = null;

        return $supermarket;
    }

    private function product(string $name): Product
    {
        return Product::create(['tenant_id' => $this->tenant->id, 'name' => $name]);
    }

    /**
     * The CustomerOfferItem observer derives the margin from purchase/sale prices, so
     * with purchase 1.00 the sale price encodes the margin (% = sale-1, value = sale-1).
     */
    private function offer(Customer $supermarket, Product $product, string $status, float $salePrice, ?string $updatedAt = null): void
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
            'product_id' => $product->id,
            'quantity' => 100,
            'purchase_price' => 1.00,
            'sale_price' => $salePrice,
        ]);

        if ($updatedAt !== null) {
            DB::table('customer_offers')->where('id', $offer->id)->update(['updated_at' => $updatedAt]);
        }
    }

    private function dataFor(array $filters): array
    {
        $widget = new SupermarketProductMarginChart;
        $widget->filters = $filters;

        return (new ReflectionMethod($widget, 'getData'))->invoke($widget);
    }

    public function test_the_report_page_is_registered_as_a_filament_route(): void
    {
        $this->assertTrue(Route::has('filament.admin.pages.reports.supermarket-product-margins'));
    }

    public function test_without_a_supermarket_the_chart_has_no_datasets(): void
    {
        $data = $this->dataFor(['supermarket_id' => null]);

        $this->assertSame([], $data['datasets']);
    }

    public function test_products_split_into_green_accepted_and_red_rejected_labelled_by_product(): void
    {
        $mere = $this->product('Mere');
        $pere = $this->product('Pere');

        $this->offer($this->supermarket, $mere, 'accepted', salePrice: 1.20); // 20%
        $this->offer($this->supermarket, $pere, 'rejected', salePrice: 1.35); // 35%

        $data = $this->dataFor([
            'supermarket_id' => $this->supermarket->id,
            'margin_type' => 'percent',
        ]);

        [$accepted, $rejected] = $data['datasets'];

        $this->assertSame('#16a34a', $accepted['backgroundColor']);
        $this->assertSame(['Mere'], $accepted['pointLabels']);
        $this->assertEquals(20.0, $accepted['data'][0]['y']);

        $this->assertSame('#dc2626', $rejected['backgroundColor']);
        $this->assertSame(['Pere'], $rejected['pointLabels']);
        $this->assertEquals(35.0, $rejected['data'][0]['y']);
    }

    public function test_only_the_selected_supermarkets_offers_are_plotted(): void
    {
        $auchan = $this->createSupermarket('Auchan');
        $mere = $this->product('Mere');

        $this->offer($this->supermarket, $mere, 'accepted', salePrice: 1.20);
        $this->offer($auchan, $mere, 'accepted', salePrice: 1.25);

        $data = $this->dataFor([
            'supermarket_id' => $this->supermarket->id,
            'margin_type' => 'percent',
        ]);

        $this->assertCount(1, $data['datasets'][0]['data']);
        $this->assertSame(['Mere'], $data['datasets'][0]['pointLabels']);
    }

    public function test_product_filter_narrows_to_one_product(): void
    {
        $mere = $this->product('Mere');
        $pere = $this->product('Pere');

        $this->offer($this->supermarket, $mere, 'accepted', salePrice: 1.20);
        $this->offer($this->supermarket, $pere, 'accepted', salePrice: 1.25);

        $data = $this->dataFor([
            'supermarket_id' => $this->supermarket->id,
            'product_id' => $mere->id,
            'margin_type' => 'percent',
        ]);

        $this->assertSame(['Mere'], $data['datasets'][0]['pointLabels']);
    }

    public function test_each_offer_status_becomes_its_own_coloured_dataset(): void
    {
        $mere = $this->product('Mere');
        $pere = $this->product('Pere');
        $prune = $this->product('Prune');
        $caise = $this->product('Caise');
        $struguri = $this->product('Struguri');

        $this->offer($this->supermarket, $mere, 'accepted', salePrice: 1.20);
        $this->offer($this->supermarket, $pere, 'sent', salePrice: 1.30);
        $this->offer($this->supermarket, $prune, 'draft', salePrice: 1.40);
        $this->offer($this->supermarket, $caise, 'expired', salePrice: 1.50);
        $this->offer($this->supermarket, $struguri, 'cancelled', salePrice: 1.60);

        $data = $this->dataFor([
            'supermarket_id' => $this->supermarket->id,
            'margin_type' => 'percent',
        ]);

        $colors = collect($data['datasets'])->pluck('backgroundColor')->all();

        $this->assertContains('#16a34a', $colors); // accepted (green)
        $this->assertContains('#2563eb', $colors); // sent (blue)
        $this->assertContains('#9ca3af', $colors); // draft (gray)
        $this->assertContains('#d97706', $colors); // expired (amber)
        $this->assertContains('#475569', $colors); // cancelled (slate)
        $this->assertNotContains('#dc2626', $colors); // no rejected offers → no red dataset

        // Only accepted offers are joined by a line.
        $accepted = collect($data['datasets'])->firstWhere('backgroundColor', '#16a34a');
        $sent = collect($data['datasets'])->firstWhere('backgroundColor', '#2563eb');
        $this->assertTrue($accepted['showLine']);
        $this->assertFalse($sent['showLine']);
    }

    public function test_supermarket_query_param_preselects_the_supermarket_filter(): void
    {
        Livewire::withQueryParams(['supermarket' => $this->supermarket->id])
            ->test(SupermarketProductMarginChart::class)
            ->assertSet('filters.supermarket_id', $this->supermarket->id);
    }

    public function test_the_report_page_renders_with_the_chart_widget(): void
    {
        $mere = $this->product('Mere');
        $this->offer($this->supermarket, $mere, 'accepted', salePrice: 1.20);

        Livewire::test(SupermarketProductMarginReport::class)->assertSuccessful();
        Livewire::test(SupermarketProductMarginChart::class)->assertSuccessful();
    }
}
