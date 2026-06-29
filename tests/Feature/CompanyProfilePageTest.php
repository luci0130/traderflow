<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Producers\Filament\Pages\CompanyProfile;
use App\Modules\Producers\Models\Producer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class CompanyProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private Producer $producer;

    private User $producerUser;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('producer');

        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => 'producer', 'guard_name' => 'web', 'tenant_id' => null]);

        $this->producer = Producer::create([
            'name' => 'Acme Foods',
            'status' => 'active',
        ]);

        $this->producerUser = User::factory()->create(['producer_id' => $this->producer->id]);
        $this->producerUser->assignRole('producer');
    }

    public function test_form_loads_existing_company_data(): void
    {
        $this->producer->update([
            'legal_name' => 'Acme Foods SRL',
            'vat_number' => 'RO12345678',
            'iban' => 'RO49AAAA1B31007593840000',
        ]);

        $this->actingAs($this->producerUser);

        Livewire::test(CompanyProfile::class)
            ->assertSuccessful()
            ->assertFormSet([
                'name' => 'Acme Foods',
                'legal_name' => 'Acme Foods SRL',
                'vat_number' => 'RO12345678',
                'iban' => 'RO49AAAA1B31007593840000',
            ]);
    }

    public function test_save_persists_invoice_fields(): void
    {
        $this->actingAs($this->producerUser);

        Livewire::test(CompanyProfile::class)
            ->fillForm([
                'name' => 'Acme Foods',
                'legal_name' => 'Acme Foods SRL',
                'vat_number' => 'RO12345678',
                'registration_number' => 'J40/1234/2024',
                'email' => 'hello@acmefoods.eu',
                'phone' => '+40 21 555 0100',
                'country' => 'RO',
                'city' => 'București',
                'postal_code' => '010101',
                'address' => 'Str. Exemplu 12',
                'iban' => 'RO49AAAA1B31007593840000',
                'bank_name' => 'Banca Transilvania',
                'bank_swift' => 'BTRLRO22',
                'default_currency' => 'EUR',
                'invoice_prefix' => 'ACME-',
                'invoice_starting_number' => 1000,
                'invoice_notes' => 'Payment due within 14 days.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $this->producer->refresh();
        $this->assertSame('Acme Foods SRL', $fresh->legal_name);
        $this->assertSame('J40/1234/2024', $fresh->registration_number);
        $this->assertSame('RO49AAAA1B31007593840000', $fresh->iban);
        $this->assertSame('ACME-', $fresh->invoice_prefix);
        $this->assertSame(1000, $fresh->invoice_starting_number);
    }

    public function test_save_requires_company_name(): void
    {
        $this->actingAs($this->producerUser);

        Livewire::test(CompanyProfile::class)
            ->fillForm(['name' => ''])
            ->call('save')
            ->assertHasFormErrors(['name' => 'required']);
    }
}
