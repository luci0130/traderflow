<?php

declare(strict_types=1);

namespace App\Modules\NumberSequences\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Modules\NumberSequences\Models\NumberSequence;
use Illuminate\Auth\Access\HandlesAuthorization;

class NumberSequencePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:NumberSequence');
    }

    public function view(AuthUser $authUser, NumberSequence $numberSequence): bool
    {
        return $authUser->can('View:NumberSequence');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:NumberSequence');
    }

    public function update(AuthUser $authUser, NumberSequence $numberSequence): bool
    {
        return $authUser->can('Update:NumberSequence');
    }

    public function delete(AuthUser $authUser, NumberSequence $numberSequence): bool
    {
        return $authUser->can('Delete:NumberSequence');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:NumberSequence');
    }

    public function restore(AuthUser $authUser, NumberSequence $numberSequence): bool
    {
        return $authUser->can('Restore:NumberSequence');
    }

    public function forceDelete(AuthUser $authUser, NumberSequence $numberSequence): bool
    {
        return $authUser->can('ForceDelete:NumberSequence');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:NumberSequence');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:NumberSequence');
    }

    public function replicate(AuthUser $authUser, NumberSequence $numberSequence): bool
    {
        return $authUser->can('Replicate:NumberSequence');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:NumberSequence');
    }

}