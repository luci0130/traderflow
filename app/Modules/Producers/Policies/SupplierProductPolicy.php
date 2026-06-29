<?php

declare(strict_types=1);

namespace App\Modules\Producers\Policies;

use App\Modules\Producers\Models\SupplierProduct;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierProductPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierProduct');
    }

    public function view(AuthUser $authUser, SupplierProduct $supplierProduct): bool
    {
        return $authUser->can('View:SupplierProduct');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierProduct');
    }

    public function update(AuthUser $authUser, SupplierProduct $supplierProduct): bool
    {
        return $authUser->can('Update:SupplierProduct');
    }

    public function delete(AuthUser $authUser, SupplierProduct $supplierProduct): bool
    {
        return $authUser->can('Delete:SupplierProduct');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierProduct');
    }

    public function restore(AuthUser $authUser, SupplierProduct $supplierProduct): bool
    {
        return $authUser->can('Restore:SupplierProduct');
    }

    public function forceDelete(AuthUser $authUser, SupplierProduct $supplierProduct): bool
    {
        return $authUser->can('ForceDelete:SupplierProduct');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierProduct');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierProduct');
    }

    public function replicate(AuthUser $authUser, SupplierProduct $supplierProduct): bool
    {
        return $authUser->can('Replicate:SupplierProduct');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierProduct');
    }
}
