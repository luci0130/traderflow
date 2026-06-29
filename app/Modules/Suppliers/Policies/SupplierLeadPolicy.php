<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Policies;

use App\Modules\Suppliers\Models\SupplierLead;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierLeadPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierLead');
    }

    public function view(AuthUser $authUser, SupplierLead $supplierLead): bool
    {
        return $authUser->can('View:SupplierLead');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierLead');
    }

    public function update(AuthUser $authUser, SupplierLead $supplierLead): bool
    {
        return $authUser->can('Update:SupplierLead');
    }

    public function delete(AuthUser $authUser, SupplierLead $supplierLead): bool
    {
        return $authUser->can('Delete:SupplierLead');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierLead');
    }

    public function restore(AuthUser $authUser, SupplierLead $supplierLead): bool
    {
        return $authUser->can('Restore:SupplierLead');
    }

    public function forceDelete(AuthUser $authUser, SupplierLead $supplierLead): bool
    {
        return $authUser->can('ForceDelete:SupplierLead');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierLead');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierLead');
    }

    public function replicate(AuthUser $authUser, SupplierLead $supplierLead): bool
    {
        return $authUser->can('Replicate:SupplierLead');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierLead');
    }
}
