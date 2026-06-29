<?php

declare(strict_types=1);

namespace App\Modules\MarketComparison\Policies;

use App\Modules\MarketComparison\Models\Transporter;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TransporterPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Transporter');
    }

    public function view(AuthUser $authUser, Transporter $transporter): bool
    {
        return $authUser->can('View:Transporter');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Transporter');
    }

    public function update(AuthUser $authUser, Transporter $transporter): bool
    {
        return $authUser->can('Update:Transporter');
    }

    public function delete(AuthUser $authUser, Transporter $transporter): bool
    {
        return $authUser->can('Delete:Transporter');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Transporter');
    }

    public function restore(AuthUser $authUser, Transporter $transporter): bool
    {
        return $authUser->can('Restore:Transporter');
    }

    public function forceDelete(AuthUser $authUser, Transporter $transporter): bool
    {
        return $authUser->can('ForceDelete:Transporter');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Transporter');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Transporter');
    }

    public function replicate(AuthUser $authUser, Transporter $transporter): bool
    {
        return $authUser->can('Replicate:Transporter');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Transporter');
    }
}
