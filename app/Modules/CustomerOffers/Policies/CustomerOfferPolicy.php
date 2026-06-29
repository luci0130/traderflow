<?php

declare(strict_types=1);

namespace App\Modules\CustomerOffers\Policies;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CustomerOfferPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CustomerOffer');
    }

    public function view(AuthUser $authUser, CustomerOffer $customerOffer): bool
    {
        return $authUser->can('View:CustomerOffer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CustomerOffer');
    }

    public function update(AuthUser $authUser, CustomerOffer $customerOffer): bool
    {
        return $authUser->can('Update:CustomerOffer');
    }

    public function delete(AuthUser $authUser, CustomerOffer $customerOffer): bool
    {
        return $authUser->can('Delete:CustomerOffer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CustomerOffer');
    }

    public function restore(AuthUser $authUser, CustomerOffer $customerOffer): bool
    {
        return $authUser->can('Restore:CustomerOffer');
    }

    public function forceDelete(AuthUser $authUser, CustomerOffer $customerOffer): bool
    {
        return $authUser->can('ForceDelete:CustomerOffer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CustomerOffer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CustomerOffer');
    }

    public function replicate(AuthUser $authUser, CustomerOffer $customerOffer): bool
    {
        return $authUser->can('Replicate:CustomerOffer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CustomerOffer');
    }
}
