<?php

namespace Tests\Feature;

use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\TenantSettings\Services\TenantBankAccounts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class TenantBankAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_round_trips_bank_accounts(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'currency' => 'RON']);

        TenantBankAccounts::set($tenant, [
            ['bank' => 'ING BANK', 'iban' => 'RO92INGB0000999907669336', 'currency' => 'RON'],
            ['bank' => '', 'iban' => '', 'currency' => 'EUR'], // dropped (empty)
        ]);

        $accounts = TenantBankAccounts::get($tenant);

        $this->assertCount(1, $accounts);
        $this->assertSame('ING BANK', $accounts[0]['bank']);
        $this->assertSame('RO92INGB0000999907669336', $accounts[0]['iban']);
        $this->assertSame('RON', $accounts[0]['currency']);
    }

    public function test_editing_a_tenant_loads_and_persists_bank_accounts(): void
    {
        $tenant = Tenant::create(['name' => 'Merchant', 'currency' => 'RON']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Existing accounts are loaded into the repeater.
        TenantBankAccounts::set($tenant, [
            ['bank' => 'Old Bank', 'iban' => 'RO00', 'currency' => 'RON'],
        ]);

        Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
            ->assertSuccessful()
            ->assertFormExists()
            ->fillForm([
                'bank_accounts' => [
                    ['bank' => 'ING BANK', 'iban' => 'RO92', 'currency' => 'RON'],
                    ['bank' => 'RAIFFEISEN', 'iban' => 'RO63', 'currency' => 'EUR'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $accounts = TenantBankAccounts::get($tenant->refresh());
        $this->assertCount(2, $accounts);
        $this->assertSame('ING BANK', $accounts[0]['bank']);
        $this->assertSame('EUR', $accounts[1]['currency']);
        // The virtual field must not be written to the tenants table.
        $this->assertArrayNotHasKey('bank_accounts', $tenant->getAttributes());
    }
}
