<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\SeedsTenantRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function setPermissionsTeamId;

class DatabaseSeeder extends Seeder
{
    use SeedsTenantRoles;

    public function run(): void
    {
        $this->bootstrapGlobalProducerRole();

        // The demo company exists so documents (offers/orders) have a tenant to
        // be stamped with, but users are not bound to it.
        $this->getOrCreatePrimaryTenant();

        $this->bootstrapGlobalRoles();

        $admin = User::firstOrCreate(
            ['email' => $this->adminUser['email']],
            [
                'name' => $this->adminUser['name'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $this->assignGlobalRole($admin, 'super_admin');
    }

    /**
     * Create the global (tenant_id=NULL) producer role and grant it the
     * narrow set of permissions needed to operate the producer panel.
     */
    protected function bootstrapGlobalProducerRole(): void
    {
        setPermissionsTeamId(null);

        $role = Role::firstOrCreate([
            'name' => 'producer',
            'guard_name' => 'web',
            'tenant_id' => null,
        ]);

        $supplierProductPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [
                'ViewAny:SupplierProduct',
                'View:SupplierProduct',
                'Create:SupplierProduct',
                'Update:SupplierProduct',
                'Delete:SupplierProduct',
            ])
            ->get();

        $role->syncPermissions($supplierProductPermissions);
    }
}
