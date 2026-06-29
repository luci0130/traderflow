<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the admin-panel guided tutorials that a user is
 * allowed to start. The keys must match the tour keys defined in
 * resources/js/filament-tours.js; the role gating mirrors the permission matrix
 * in Database\Seeders\Concerns\SeedsTenantRoles so a tour is only offered to a
 * user who can actually perform it.
 */
class TutorialCatalog
{
    /**
     * @var array<int, array{key: string, label: string, roles: array<int, string>}>
     */
    protected const TUTORIALS = [
        ['key' => 'admin_welcome', 'label' => 'Prezentare generală', 'roles' => ['*']],
        ['key' => 'add_customer', 'label' => 'Cum adaugi un client', 'roles' => ['sales_agent']],
        ['key' => 'create_customer_offer', 'label' => 'Cum creezi o ofertă pentru client', 'roles' => ['sales_agent']],
        ['key' => 'add_supplier', 'label' => 'Cum adaugi un furnizor', 'roles' => ['purchasing_agent']],
        ['key' => 'create_supplier_offer', 'label' => 'Cum creezi o ofertă de la furnizor', 'roles' => ['purchasing_agent']],
        ['key' => 'add_supplier_product', 'label' => 'Cum adaugi un produs de furnizor', 'roles' => ['purchasing_agent']],
        ['key' => 'add_product', 'label' => 'Cum adaugi un produs în catalog', 'roles' => ['super_admin']],
    ];

    /**
     * The tutorials the given user may start, in display order.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function forUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $isSuperAdmin = $user->isSuperAdmin();

        $available = array_filter(self::TUTORIALS, static function (array $tutorial) use ($user, $isSuperAdmin): bool {
            if (in_array('*', $tutorial['roles'], true) || $isSuperAdmin) {
                return true;
            }

            foreach ($tutorial['roles'] as $role) {
                if ($user->hasGlobalRole($role)) {
                    return true;
                }
            }

            return false;
        });

        return array_values(array_map(
            static fn (array $tutorial): array => ['key' => $tutorial['key'], 'label' => $tutorial['label']],
            $available,
        ));
    }
}
