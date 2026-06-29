<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Mail\CustomerOfferMail;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class EditCustomerOfferEmailActionTest extends TestCase
{
    use RefreshDatabase;

    private CustomerOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'View:CustomerOffer', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:CustomerOffer', 'guard_name' => 'web']),
        );

        $this->actingAs($user);
        Filament::setTenant($tenant);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer A',
            'email' => 'customer@example.com',
        ]);

        $this->offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'offer_number' => 'CO-001',
            'currency' => 'RON',
            'status' => 'draft',
        ]);
    }

    public function test_send_offer_email_action_validates_and_sends(): void
    {
        Mail::fake();

        Livewire::test(EditCustomerOffer::class, ['record' => $this->offer->getRouteKey()])
            ->callAction('sendOfferEmail', data: [
                'to' => 'buyer@example.com, manager@example.com',
                'cc' => '',
                'subject' => 'Your offer',
                'body' => '<p>Hi, here is your offer.</p>',
            ])
            ->assertHasNoActionErrors();

        Mail::assertSent(CustomerOfferMail::class);
    }

    public function test_generate_excel_offer_action_downloads_a_file(): void
    {
        Livewire::test(EditCustomerOffer::class, ['record' => $this->offer->getRouteKey()])
            ->callAction('generateOfferExcel')
            ->assertFileDownloaded('oferta-CO-001.xlsx');
    }

    public function test_send_offer_email_action_rejects_invalid_addresses(): void
    {
        Mail::fake();

        Livewire::test(EditCustomerOffer::class, ['record' => $this->offer->getRouteKey()])
            ->callAction('sendOfferEmail', data: [
                'to' => 'not-an-email',
                'cc' => '',
                'subject' => 'Your offer',
                'body' => '<p>Hi</p>',
            ])
            ->assertHasActionErrors(['to']);

        Mail::assertNothingSent();
    }
}
