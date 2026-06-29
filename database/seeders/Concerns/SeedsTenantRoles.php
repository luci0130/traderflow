<?php

namespace Database\Seeders\Concerns;

use App\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function setPermissionsTeamId;

/**
 * Shared definition of the application's three access roles and the
 * tenant/admin identity used to converge the seeders on a single demo
 * company. Used by both DatabaseSeeder and the demo seeders so the
 * permission matrix lives in exactly one place.
 */
trait SeedsTenantRoles
{
    /**
     * The single super-admin user that every seeder converges on.
     *
     * @var array{name: string, email: string}
     */
    protected array $adminUser = [
        'name' => 'Mihai Popescu',
        'email' => 'mihai@traderflow.test',
    ];

    /**
     * The primary demo company. Both seeders reuse it (firstOrCreate) so the
     * admin and the two agents end up in the same tenant.
     *
     * @var array<string, string>
     */
    protected array $primaryTenant = [
        'name' => 'Freshmarket București',
        'legal_name' => 'Freshmarket Distribuție SRL',
        'currency' => 'RON',
        'country' => 'România',
        'city' => 'București',
        'email' => 'contact@freshmarket.test',
    ];

    /**
     * The only three roles the application exposes.
     *
     * @var list<string>
     */
    protected array $roleNames = ['super_admin', 'sales_agent', 'purchasing_agent'];

    /**
     * Subjects each agent role fully owns (every permission verb is granted).
     *
     * @var array<string, list<string>>
     */
    protected array $ownedSubjects = [
        'sales_agent' => ['Customer', 'CustomerOffer', 'SalesOrder', 'SupermarketPrice', 'SupermarketProduct'],
        'purchasing_agent' => ['Supplier', 'SupplierOffer', 'SupplierProduct', 'SupplierOrder', 'SupplierLead', 'Transporter'],
    ];

    /**
     * Reference subjects the purchasing agent may view (read only). The sales
     * agent has no catalog access; the purchasing agent keeps only canonical
     * products (no Product / ProductCategory / Unit / PackagingMethod catalog).
     *
     * @var list<string>
     */
    protected array $sharedViewSubjects = ['CanonicalProduct'];

    /**
     * Page-level permissions granted to the sales agent (supermarket tooling
     * and the market-analytics pages).
     *
     * @var list<string>
     */
    protected array $salesPagePermissions = [
        'View:BestPrices',
        'View:MarketComparison',
        'View:UploadPricePhotos',
        'View:ReviewPricePhotos',
    ];

    protected function getOrCreatePrimaryTenant(): Tenant
    {
        return Tenant::firstOrCreate(
            ['name' => $this->primaryTenant['name']],
            [...$this->primaryTenant, 'is_active' => true],
        );
    }

    /**
     * Create the three roles as GLOBAL roles (tenant_id = null) and sync their
     * permissions according to the access matrix. Users are not bound to a
     * tenant; permissions apply across every tenant. Permission syncing is
     * skipped when none exist yet (e.g. test databases that never ran
     * `shield:generate`).
     */
    protected function bootstrapGlobalRoles(): void
    {
        setPermissionsTeamId(null);

        $roles = [];
        foreach ($this->roleNames as $name) {
            $roles[$name] = Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
                'tenant_id' => null,
            ]);
        }

        $permissions = Permission::query()->where('guard_name', 'web')->get();

        if ($permissions->isEmpty()) {
            return;
        }

        $roles['super_admin']->syncPermissions($permissions);

        $subjectOf = static fn (Permission $permission): ?string => array_pad(explode(':', $permission->name, 2), 2, null)[1];
        $prefixOf = static fn (Permission $permission): string => explode(':', $permission->name, 2)[0];

        $salesPermissions = $permissions
            ->filter(fn (Permission $permission): bool => in_array($subjectOf($permission), $this->ownedSubjects['sales_agent'], true))
            ->merge($permissions->filter(fn (Permission $permission): bool => in_array($permission->name, $this->salesPagePermissions, true)))
            ->unique('id');

        $roles['sales_agent']->syncPermissions($salesPermissions);

        $purchasingPermissions = $permissions
            ->filter(fn (Permission $permission): bool => in_array($subjectOf($permission), $this->ownedSubjects['purchasing_agent'], true))
            ->merge($permissions->filter(fn (Permission $permission): bool => in_array($subjectOf($permission), $this->sharedViewSubjects, true) && in_array($prefixOf($permission), ['ViewAny', 'View'], true)))
            ->unique('id');

        $roles['purchasing_agent']->syncPermissions($purchasingPermissions);
    }

    /**
     * Assign a global role to a user (team context = null). The user is NOT
     * attached to any tenant — they have global access across the business.
     */
    protected function assignGlobalRole(\App\Models\User $user, string $roleName): void
    {
        setPermissionsTeamId(null);
        $user->assignRole($roleName);
    }
}
