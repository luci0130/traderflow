<?php

declare(strict_types=1);

namespace App\Modules\MarketComparison\Policies;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CanonicalProductPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CanonicalProduct');
    }

    public function view(AuthUser $authUser, CanonicalProduct $canonicalProduct): bool
    {
        return $authUser->can('View:CanonicalProduct');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CanonicalProduct');
    }

    public function update(AuthUser $authUser, CanonicalProduct $canonicalProduct): bool
    {
        return $authUser->can('Update:CanonicalProduct');
    }

    public function delete(AuthUser $authUser, CanonicalProduct $canonicalProduct): bool
    {
        return $authUser->can('Delete:CanonicalProduct');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CanonicalProduct');
    }

    public function restore(AuthUser $authUser, CanonicalProduct $canonicalProduct): bool
    {
        return $authUser->can('Restore:CanonicalProduct');
    }

    public function forceDelete(AuthUser $authUser, CanonicalProduct $canonicalProduct): bool
    {
        return $authUser->can('ForceDelete:CanonicalProduct');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CanonicalProduct');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CanonicalProduct');
    }

    public function replicate(AuthUser $authUser, CanonicalProduct $canonicalProduct): bool
    {
        return $authUser->can('Replicate:CanonicalProduct');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CanonicalProduct');
    }
}
