<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Producers\Models\Producer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use STS\FilamentImpersonate\Facades\Impersonation as Impersonate;
use Tests\TestCase;

use function setPermissionsTeamId;

class ImpersonateProducerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_impersonate_a_producer_user(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'is_active' => true, 'currency' => 'EUR']);
        setPermissionsTeamId($tenant->getKey());
        $superRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $tenant->getKey()]);
        $admin = User::factory()->create();
        $admin->assignRole($superRole);

        setPermissionsTeamId(null);
        Role::create(['name' => 'producer', 'guard_name' => 'web', 'tenant_id' => null]);

        $producer = Producer::create(['name' => 'Producer A', 'status' => 'active']);
        $producerUser = User::factory()->create(['producer_id' => $producer->id]);
        $producerUser->assignRole('producer');

        $this->actingAs($admin);

        $result = Impersonate::enter($admin, $producerUser, 'web');

        $this->assertTrue($result, 'Super admin should be able to enter impersonation.');
        $this->assertSame($producerUser->id, auth()->id(), 'Auth must switch to the impersonated user.');
        $this->assertTrue(Impersonate::isImpersonating());
    }

    public function test_leaving_impersonation_restores_original_user(): void
    {
        setPermissionsTeamId(null);
        Role::create(['name' => 'producer', 'guard_name' => 'web', 'tenant_id' => null]);

        $producer = Producer::create(['name' => 'Producer A', 'status' => 'active']);
        $producerUser = User::factory()->create(['producer_id' => $producer->id]);
        $producerUser->assignRole('producer');

        $admin = User::factory()->create();
        $this->actingAs($admin);

        Impersonate::enter($admin, $producerUser, 'web');
        $this->assertSame($producerUser->id, auth()->id());

        Impersonate::leave();
        $this->assertSame($admin->id, auth()->id(), 'Auth must revert to the original admin.');
    }
}
