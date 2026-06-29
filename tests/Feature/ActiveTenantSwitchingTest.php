<?php

namespace Tests\Feature;

use App\Http\Middleware\SyncActiveFilamentTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Dashboard\Support\DashboardScope;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

use function getPermissionsTeamId;
use function setPermissionsTeamId;

class ActiveTenantSwitchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_user_can_act_on_behalf_of_all_active_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);
        Tenant::create(['name' => 'Inactive Tenant', 'is_active' => false]);
        $user = User::factory()->create();

        // Users are not bound to a tenant: they can choose any active tenant for a document.
        $this->assertSame([$tenantA->id, $tenantB->id], $user->getTenants(filament()->getDefaultPanel())->pluck('id')->all());
        $this->assertTrue($user->canAccessTenant($tenantA));
        $this->assertTrue($user->canAccessTenant($tenantB));
    }

    public function test_filament_tenant_middleware_stores_active_tenant_in_session_and_spatie(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        Filament::setTenant($tenant, isQuiet: true);

        app(SyncActiveFilamentTenant::class)->handle(
            Request::create('/tenant/'.$tenant->id),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame($tenant->id, session('tenant_id'));
        $this->assertSame($tenant->id, getPermissionsTeamId());

        Filament::setTenant(null, isQuiet: true);
    }

    public function test_filament_module_routes_are_registered_without_tenant_segment(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        $this->actingAs($user);
        session(['tenant_id' => $tenant->id]);

        $this->assertSame('/products', parse_url(ProductResource::getUrl(), PHP_URL_PATH));
    }

    public function test_super_admin_can_switch_between_global_and_active_tenant_scope(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);
        $user = User::factory()->create();

        Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'tenant_id' => $tenantA->id,
        ]);

        setPermissionsTeamId($tenantA->id);
        $user->assignRole('super_admin');

        $this
            ->actingAs($user)
            ->withSession(['tenant_id' => $tenantA->id])
            ->get(route('active-tenant.global'))
            ->assertRedirect('/');

        $this->assertNull(session('tenant_id'));
        $this->assertTrue(session(DashboardScope::SESSION_KEY));

        $this
            ->actingAs($user)
            ->get(route('active-tenant.switch', ['tenant' => $tenantB]))
            ->assertRedirect('/');

        $this->assertSame($tenantB->id, session('tenant_id'));
        $this->assertFalse(session()->has(DashboardScope::SESSION_KEY));
    }

    public function test_documents_fill_tenant_id_and_scope_queries_to_the_active_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);
        $customer = Customer::create(['name' => 'Client', 'tenant_id' => null]);

        session(['tenant_id' => $tenantA->id]);

        // BelongsToTenant auto-fills tenant_id from the active tenant on documents.
        $created = CustomerOffer::create([
            'customer_id' => $customer->id,
            'offer_number' => 'A-1',
            'offer_date' => now(),
            'currency' => 'RON',
            'status' => 'draft',
        ]);

        CustomerOffer::create([
            'tenant_id' => $tenantB->id,
            'customer_id' => $customer->id,
            'offer_number' => 'B-1',
            'offer_date' => now(),
            'currency' => 'RON',
            'status' => 'draft',
        ]);

        $this->assertSame($tenantA->id, $created->tenant_id);
        $this->assertSame(['A-1'], CustomerOffer::query()->pluck('offer_number')->all());
        $this->assertSame(2, CustomerOffer::withoutGlobalScope('active_tenant')->count());
    }

    public function test_super_admin_bypasses_the_model_tenant_scope(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);
        $user = User::factory()->create();

        Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'tenant_id' => $tenantA->id,
        ]);

        setPermissionsTeamId($tenantA->id);
        $user->assignRole('super_admin');

        Product::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Rosii',
        ]);
        Product::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Ciment',
        ]);

        $this->actingAs($user);
        session(['tenant_id' => $tenantA->id]);

        $this->assertSame(2, Product::query()->count());
    }
}
