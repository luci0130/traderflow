<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class UserResourceAclTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    private User $managerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'is_active' => true, 'currency' => 'EUR']);

        Filament::setCurrentPanel('admin');

        // Roles are global (tenant_id = null); users are not bound to a tenant.
        setPermissionsTeamId(null);

        $superRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]);
        $managerRole = Role::create(['name' => 'manager', 'guard_name' => 'web', 'tenant_id' => null]);

        Permission::firstOrCreate(['name' => 'ViewAny:Customer', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ViewAny:User', 'guard_name' => 'web']);

        $managerRole->givePermissionTo('ViewAny:Customer');

        $this->superAdmin = User::factory()->create(['email' => 'super@acme.test']);
        $this->superAdmin->assignRole($superRole);

        $this->managerUser = User::factory()->create(['email' => 'manager@acme.test']);
        $this->managerUser->assignRole($managerRole);
    }

    public function test_user_resource_navigation_is_restricted_to_super_admin(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(UserResource::canAccess(), 'Super admin should access UserResource.');

        $this->actingAs($this->managerUser);
        $this->assertFalse(UserResource::canAccess(), 'Manager should not access UserResource.');
    }

    public function test_super_admin_can_list_users(): void
    {
        $this->actingAs($this->superAdmin);
        Filament::setTenant($this->tenant);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->superAdmin, $this->managerUser]);
    }

    public function test_super_admin_can_create_user_with_global_role(): void
    {
        $this->actingAs($this->superAdmin);
        Filament::setTenant($this->tenant);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Member',
                'email' => 'new@acme.test',
                'password' => 'secret-pass-123',
                'roles' => ['manager'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'new@acme.test')->firstOrFail();

        setPermissionsTeamId(null);
        $created->load('roles');
        $this->assertTrue($created->hasRole('manager'));
    }

    public function test_edit_user_form_loads_existing_global_roles(): void
    {
        $this->actingAs($this->superAdmin);
        Filament::setTenant($this->tenant);

        Livewire::test(EditUser::class, ['record' => $this->managerUser->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet([
                'name' => $this->managerUser->name,
                'email' => $this->managerUser->email,
            ]);
    }

    public function test_super_admin_bypasses_resource_authorization(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertTrue(CustomerResource::canViewAny());
    }

    public function test_role_permissions_govern_resource_access_for_non_super_admin(): void
    {
        $this->actingAs($this->managerUser);
        setPermissionsTeamId(null);

        $this->assertTrue(CustomerResource::canViewAny(), 'Manager has ViewAny:Customer permission.');
    }

    public function test_user_without_permission_cannot_access_resource(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger);

        $this->assertFalse(CustomerResource::canViewAny(), 'User without ViewAny:Customer must be denied.');
    }
}
