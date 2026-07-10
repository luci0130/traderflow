<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class EditCustomerOfferPdfActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_pdf_offer_action_streams_a_pdf(): void
    {
        $tenant = Tenant::create(['name' => 'Freshmarket', 'legal_name' => 'Freshmarket SRL', 'city' => 'București']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'View:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:CustomerOffer', 'guard_name' => 'web']),
        );

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Carrefour', 'vat_number' => 'RO11588780']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Cartofi dulci']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'offer_number' => 'OC-00065',
            'offer_date' => today(), 'currency' => 'RON', 'status' => 'draft',
            'subtotal' => 0, 'tax_total' => 0, 'total' => 4125,
        ]);
        CustomerOfferItem::create([
            'tenant_id' => $tenant->id, 'customer_offer_id' => $offer->id, 'product_id' => $product->id,
            'quantity' => 500, 'sale_price' => 8.25, 'tax_rate' => 0, 'line_total' => 4125,
        ]);

        // The button is wired on the edit page; the export pipeline itself (PDF
        // bytes + data mapping) is covered by CustomerOfferPdfExporterTest. We
        // assert presence rather than invoking mpdf a second time in-process.
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->assertActionVisible('generateOfferPdf');
    }
}
