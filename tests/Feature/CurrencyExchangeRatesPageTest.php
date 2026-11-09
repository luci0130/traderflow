<?php

namespace Tests\Feature;

use App\Filament\Resources\CurrencyExchangeRates\CurrencyExchangeRateResource;
use App\Filament\Resources\CurrencyExchangeRates\Pages\ListCurrencyExchangeRates;
use App\Models\CurrencyExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CurrencyExchangeRatesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_and_filter_the_read_only_rate_history(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $eur = CurrencyExchangeRate::factory()->create();
        $ron = CurrencyExchangeRate::factory()->inverse()->create();

        $this->get(CurrencyExchangeRateResource::getUrl())->assertOk();
        Livewire::test(ListCurrencyExchangeRates::class)
            ->assertCanSeeTableRecords([$eur, $ron])
            ->assertSee('BNR')
            ->filterTable('base_currency', 'EUR')
            ->assertCanSeeTableRecords([$eur])
            ->assertCanNotSeeTableRecords([$ron]);

        $this->assertFalse(CurrencyExchangeRateResource::canCreate());
        $this->assertSame(['index'], array_keys(CurrencyExchangeRateResource::getPages()));
    }

    public function test_regular_users_cannot_view_the_rate_administration_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(CurrencyExchangeRateResource::getUrl())
            ->assertForbidden();
    }
}
