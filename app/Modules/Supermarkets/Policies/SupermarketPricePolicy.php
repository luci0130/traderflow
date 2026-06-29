<?php

declare(strict_types=1);

namespace App\Modules\Supermarkets\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use Illuminate\Auth\Access\HandlesAuthorization;

class SupermarketPricePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupermarketPrice');
    }

    public function view(AuthUser $authUser, SupermarketPrice $supermarketPrice): bool
    {
        return $authUser->can('View:SupermarketPrice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupermarketPrice');
    }

    public function update(AuthUser $authUser, SupermarketPrice $supermarketPrice): bool
    {
        return $authUser->can('Update:SupermarketPrice');
    }

    public function delete(AuthUser $authUser, SupermarketPrice $supermarketPrice): bool
    {
        return $authUser->can('Delete:SupermarketPrice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupermarketPrice');
    }

    public function restore(AuthUser $authUser, SupermarketPrice $supermarketPrice): bool
    {
        return $authUser->can('Restore:SupermarketPrice');
    }

    public function forceDelete(AuthUser $authUser, SupermarketPrice $supermarketPrice): bool
    {
        return $authUser->can('ForceDelete:SupermarketPrice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupermarketPrice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupermarketPrice');
    }

    public function replicate(AuthUser $authUser, SupermarketPrice $supermarketPrice): bool
    {
        return $authUser->can('Replicate:SupermarketPrice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupermarketPrice');
    }

}