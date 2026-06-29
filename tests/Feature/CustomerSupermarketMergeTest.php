<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use App\Modules\Customers\Models\Customer;
use Database\Factories\SupermarketFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSupermarketMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_lists_all_customers_globally(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        $user = User::factory()->create();

        Customer::create(['tenant_id' => $tenantA->id, 'name' => 'Acme A']);
        Customer::create(['tenant_id' => $tenantB->id, 'name' => 'Acme B']);
        $global = SupermarketFactory::new()->create(['name' => 'Lidl']);

        $this->assertNull($global->tenant_id);

        $this->actingAs($user);
        session(['tenant_id' => $tenantA->id]);

        // Customers/supermarkets are global entities: every customer is visible
        // regardless of the active tenant.
        $names = CustomerResource::getEloquentQuery()->pluck('name')->all();

        sort($names);
        $this->assertSame(['Acme A', 'Acme B', 'Lidl'], $names);
    }

    public function test_global_records_are_visible_to_every_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        SupermarketFactory::new()->create(['name' => 'Kaufland']);

        $this->assertSame(1, Customer::query()->visibleToTenant($tenantA->id)->count());
        $this->assertSame(1, Customer::query()->visibleToTenant($tenantB->id)->count());
        $this->assertSame(1, Customer::query()->global()->count());
    }
}
