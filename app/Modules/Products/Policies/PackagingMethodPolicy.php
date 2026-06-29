<?php

declare(strict_types=1);

namespace App\Modules\Products\Policies;

use App\Modules\Products\Models\PackagingMethod;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PackagingMethodPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PackagingMethod');
    }

    public function view(AuthUser $authUser, PackagingMethod $packagingMethod): bool
    {
        return $authUser->can('View:PackagingMethod');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PackagingMethod');
    }

    public function update(AuthUser $authUser, PackagingMethod $packagingMethod): bool
    {
        return $authUser->can('Update:PackagingMethod');
    }

    public function delete(AuthUser $authUser, PackagingMethod $packagingMethod): bool
    {
        return $authUser->can('Delete:PackagingMethod');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PackagingMethod');
    }

    public function restore(AuthUser $authUser, PackagingMethod $packagingMethod): bool
    {
        return $authUser->can('Restore:PackagingMethod');
    }

    public function forceDelete(AuthUser $authUser, PackagingMethod $packagingMethod): bool
    {
        return $authUser->can('ForceDelete:PackagingMethod');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PackagingMethod');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PackagingMethod');
    }

    public function replicate(AuthUser $authUser, PackagingMethod $packagingMethod): bool
    {
        return $authUser->can('Replicate:PackagingMethod');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PackagingMethod');
    }
}
