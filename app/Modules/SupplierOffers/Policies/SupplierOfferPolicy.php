<?php

declare(strict_types=1);

namespace App\Modules\SupplierOffers\Policies;

use App\Modules\SupplierOffers\Models\SupplierOffer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierOfferPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierOffer');
    }

    public function view(AuthUser $authUser, SupplierOffer $supplierOffer): bool
    {
        return $authUser->can('View:SupplierOffer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierOffer');
    }

    public function update(AuthUser $authUser, SupplierOffer $supplierOffer): bool
    {
        return $authUser->can('Update:SupplierOffer');
    }

    public function delete(AuthUser $authUser, SupplierOffer $supplierOffer): bool
    {
        return $authUser->can('Delete:SupplierOffer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierOffer');
    }

    public function restore(AuthUser $authUser, SupplierOffer $supplierOffer): bool
    {
        return $authUser->can('Restore:SupplierOffer');
    }

    public function forceDelete(AuthUser $authUser, SupplierOffer $supplierOffer): bool
    {
        return $authUser->can('ForceDelete:SupplierOffer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierOffer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierOffer');
    }

    public function replicate(AuthUser $authUser, SupplierOffer $supplierOffer): bool
    {
        return $authUser->can('Replicate:SupplierOffer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierOffer');
    }
}
