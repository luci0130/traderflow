<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Enums\CustomerOfferPdfTemplate;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Services\CustomerOfferPdfExporter;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EditCustomerOfferPdfActionTest extends TestCase
{
    use RefreshDatabase;

    private function offer(): CustomerOffer
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
        $offer->items()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'quantity' => 500, 'sale_price' => 8.25, 'tax_rate' => 0, 'line_total' => 4125,
        ]);

        return $offer;
    }

    public function test_clicking_generate_pdf_opens_the_template_modal_without_exporting(): void
    {
        $offer = $this->offer();
        $this->mock(CustomerOfferPdfExporter::class)->shouldNotReceive('export');
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->mountAction('generateOfferPdf')
            ->assertMountedActionModalSee('EN - Simple Offer')
            ->assertMountedActionModalSee('RO - Simple Offer')
            ->assertMountedActionModalSee('Complex Offer')
            ->assertSchemaStateSet(['template' => 'complex'])
            ->assertNoFileDownloaded();
    }

    /** @return array<string, array{CustomerOfferPdfTemplate}> */
    public static function templates(): array
    {
        return collect(CustomerOfferPdfTemplate::cases())->mapWithKeys(fn (CustomerOfferPdfTemplate $template): array => [$template->value => [$template]])->all();
    }

    #[DataProvider('templates')]
    public function test_generate_pdf_offer_action_streams_the_selected_template(CustomerOfferPdfTemplate $template): void
    {
        $offer = $this->offer();
        $path = tempnam(sys_get_temp_dir(), 'pdf-action-test-');
        $bytes = '%PDF-1.4 selected template';
        file_put_contents($path, $bytes);
        $this->mock(CustomerOfferPdfExporter::class)->shouldReceive('export')->once()
            ->withArgs(fn (CustomerOffer $record, CustomerOfferPdfTemplate $selected): bool => $record->is($offer) && $selected === $template)
            ->andReturn($path);

        try {
            Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
                ->callAction('generateOfferPdf', data: ['template' => $template->value])
                ->assertHasNoActionErrors()
                ->assertFileDownloaded('oferta-OC-00065.pdf', $bytes, 'application/pdf');
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_template_is_required(): void
    {
        $offer = $this->offer();
        $this->mock(CustomerOfferPdfExporter::class)->shouldNotReceive('export');
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->callAction('generateOfferPdf', data: ['template' => null])
            ->assertHasActionErrors(['template' => 'required'])->assertNoFileDownloaded();
    }

    public function test_unknown_template_ids_are_rejected(): void
    {
        $offer = $this->offer();
        $this->mock(CustomerOfferPdfExporter::class)->shouldNotReceive('export');
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->callAction('generateOfferPdf', data: ['template' => '../../unauthorized-view'])
            ->assertHasActionErrors(['template'])->assertNoFileDownloaded();
    }

    public function test_accepted_offers_can_still_be_exported_without_modification(): void
    {
        $offer = $this->offer();
        $offer->update(['status' => 'accepted']);
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->getRouteKey()])
            ->mountAction('generateOfferPdf')->assertMountedActionModalSee('Complex Offer');
        $this->assertSame('accepted', $offer->fresh()->status);
    }
}
