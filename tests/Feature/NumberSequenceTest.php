<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\NumberSequences\Filament\Resources\NumberSequences\Pages\ListNumberSequences;
use App\Modules\NumberSequences\Models\NumberSequence;
use App\Modules\NumberSequences\Services\NumberSequenceGenerator;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generator_formats_and_increments_per_tenant_and_type(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);

        $generator = app(NumberSequenceGenerator::class);

        $this->assertSame('OC-00001', $generator->next($tenant->id, 'customer_offer'));
        $this->assertSame('OC-00002', $generator->next($tenant->id, 'customer_offer'));
        // A different type has its own independent counter.
        $this->assertSame('SO-00001', $generator->next($tenant->id, 'sales_order'));
    }

    public function test_sequences_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'A']);
        $tenantB = Tenant::create(['name' => 'B']);

        $generator = app(NumberSequenceGenerator::class);
        $generator->next($tenantA->id, 'customer_offer');
        $generator->next($tenantA->id, 'customer_offer');

        // Tenant B starts fresh at 1.
        $this->assertSame('OC-00001', $generator->next($tenantB->id, 'customer_offer'));
    }

    public function test_new_tenant_gets_the_default_sequence_set(): void
    {
        $tenant = Tenant::create(['name' => 'Fresh']);

        $keys = NumberSequence::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('key')->all();

        $this->assertEqualsCanonicalizing(
            ['customer_offer', 'sales_order', 'supplier_offer', 'supplier_order'],
            $keys,
        );
    }

    public function test_customer_offer_number_is_auto_filled_from_the_sequence_when_blank(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Client']);

        $first = CustomerOffer::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON', 'status' => 'draft']);
        $second = CustomerOffer::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON', 'status' => 'draft']);

        $this->assertSame('OC-00001', $first->offer_number);
        $this->assertSame('OC-00002', $second->offer_number);
    }

    public function test_a_manually_provided_number_is_kept_and_does_not_consume_the_sequence(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Client']);

        $manual = CustomerOffer::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON', 'status' => 'draft', 'offer_number' => 'CUSTOM-1']);
        $auto = CustomerOffer::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON', 'status' => 'draft']);

        $this->assertSame('CUSTOM-1', $manual->offer_number);
        // The override didn't advance the counter, so the next auto value is still 1.
        $this->assertSame('OC-00001', $auto->offer_number);
    }

    public function test_supplier_offer_number_is_auto_filled(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Ferma']);

        $offer = SupplierOffer::create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'status' => 'received', 'source_type' => 'manual']);

        $this->assertSame('OF-00001', $offer->offer_number);
    }

    public function test_settings_list_page_renders_and_shows_the_sequence_set(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        \setPermissionsTeamId($tenant->getKey());
        $user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:NumberSequence', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'View:NumberSequence', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:NumberSequence', 'guard_name' => 'web']),
        );

        Livewire::test(ListNumberSequences::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(
                NumberSequence::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get(),
            );
    }

    public function test_padding_change_is_reflected_in_the_next_number(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);

        $sequence = NumberSequence::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('key', 'customer_offer')->first();
        $sequence->update(['padding' => 0, 'prefix' => 'INV/', 'next_number' => 42]);

        $this->assertSame('INV/42', app(NumberSequenceGenerator::class)->next($tenant->id, 'customer_offer'));
    }
}
