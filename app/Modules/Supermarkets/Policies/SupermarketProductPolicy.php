<?php

declare(strict_types=1);

namespace App\Modules\Supermarkets\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Auth\Access\HandlesAuthorization;

class SupermarketProductPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupermarketProduct');
    }

    public function view(AuthUser $authUser, SupermarketProduct $supermarketProduct): bool
    {
        return $authUser->can('View:SupermarketProduct');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupermarketProduct');
    }

    public function update(AuthUser $authUser, SupermarketProduct $supermarketProduct): bool
    {
        return $authUser->can('Update:SupermarketProduct');
    }

    public function delete(AuthUser $authUser, SupermarketProduct $supermarketProduct): bool
    {
        return $authUser->can('Delete:SupermarketProduct');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupermarketProduct');
    }

    public function restore(AuthUser $authUser, SupermarketProduct $supermarketProduct): bool
    {
        return $authUser->can('Restore:SupermarketProduct');
    }

    public function forceDelete(AuthUser $authUser, SupermarketProduct $supermarketProduct): bool
    {
        return $authUser->can('ForceDelete:SupermarketProduct');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupermarketProduct');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupermarketProduct');
    }

    public function replicate(AuthUser $authUser, SupermarketProduct $supermarketProduct): bool
    {
        return $authUser->can('Replicate:SupermarketProduct');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupermarketProduct');
    }

}