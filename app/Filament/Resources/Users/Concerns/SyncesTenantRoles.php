<?php

namespace App\Filament\Resources\Users\Concerns;

use App\Models\User;

use function setPermissionsTeamId;

trait SyncesTenantRoles
{
    /**
     * Sync the user's global roles (tenant_id = null). Users are not bound to
     * any tenant; their roles apply across the whole business.
     *
     * @param  array<int, string>  $roles
     */
    protected function syncGlobalRoles(User $user, array $roles): void
    {
        setPermissionsTeamId(null);
        $user->load('roles');
        $user->syncRoles(array_values(array_filter($roles)));
        $user->load('roles');
    }

    /**
     * @return array<int, string>
     */
    protected function readGlobalRoles(User $user): array
    {
        setPermissionsTeamId(null);

        return $user->roles()->whereNull('roles.tenant_id')->pluck('name')->all();
    }
}
