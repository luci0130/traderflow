<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class SpatiePermissionTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_package_is_configured_for_tenant_scoped_roles(): void
    {
        $this->assertTrue(config('permission.teams'));
        $this->assertSame('tenant_id', config('permission.column_names.team_foreign_key'));

        $this->assertTrue(Schema::hasColumn('roles', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('model_has_roles', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('model_has_permissions', 'tenant_id'));
    }

    public function test_users_can_have_roles_scoped_to_the_active_tenant(): void
    {
        $user = User::factory()->create();

        Role::create([
            'name' => 'manager',
            'guard_name' => 'web',
            'tenant_id' => 1,
        ]);

        Role::create([
            'name' => 'manager',
            'guard_name' => 'web',
            'tenant_id' => 2,
        ]);

        setPermissionsTeamId(1);

        $user->assignRole('manager');

        $this->assertTrue($user->hasRole('manager'));

        setPermissionsTeamId(2);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertFalse($user->hasRole('manager'));
    }
}
