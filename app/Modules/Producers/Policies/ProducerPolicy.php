<?php

declare(strict_types=1);

namespace App\Modules\Producers\Policies;

use App\Modules\Producers\Models\Producer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProducerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Producer');
    }

    public function view(AuthUser $authUser, Producer $producer): bool
    {
        return $authUser->can('View:Producer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Producer');
    }

    public function update(AuthUser $authUser, Producer $producer): bool
    {
        return $authUser->can('Update:Producer');
    }

    public function delete(AuthUser $authUser, Producer $producer): bool
    {
        return $authUser->can('Delete:Producer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Producer');
    }
}
