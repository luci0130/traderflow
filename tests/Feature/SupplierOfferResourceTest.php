<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\Pages\EditSupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class SupplierOfferResourceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Tenant A']);
        $this->user = User::factory()->create();
        $this->tenant->users()->attach($this->user);

        setPermissionsTeamId($this->tenant->getKey());
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:SupplierOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'View:SupplierOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:SupplierOffer', 'guard_name' => 'web']),
        );

        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function makeOffer(?int $customerOfferId): SupplierOffer
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);

        return SupplierOffer::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'customer_offer_id' => $customerOfferId,
            'currency' => 'RON',
            'status' => 'received',
            'source_type' => 'manual',
            'received_at' => today(),
        ]);
    }

    private function makeCustomerOffer(string $number): CustomerOffer
    {
        $customer = Customer::create(['name' => 'Auchan', 'tenant_id' => $this->tenant->id]);

        return CustomerOffer::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'offer_number' => $number,
            'offer_date' => today(),
            'currency' => 'RON',
            'status' => 'draft',
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ]);
    }

    public function test_customer_offer_select_is_locked_when_already_assigned(): void
    {
        $customerOffer = $this->makeCustomerOffer('OC-0001');
        $offer = $this->makeOffer($customerOffer->id);

        Livewire::test(EditSupplierOffer::class, ['record' => $offer->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet(['customer_offer_id' => $customerOffer->id])
            ->assertFormFieldIsDisabled('customer_offer_id');
    }

    public function test_customer_offer_select_is_editable_and_assignable_when_unassigned(): void
    {
        $customerOffer = $this->makeCustomerOffer('OC-0002');
        $offer = $this->makeOffer(null);

        Livewire::test(EditSupplierOffer::class, ['record' => $offer->getRouteKey()])
            ->assertSuccessful()
            ->assertFormFieldIsEnabled('customer_offer_id')
            ->fillForm(['customer_offer_id' => $customerOffer->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($customerOffer->id, $offer->refresh()->customer_offer_id);
    }
}
