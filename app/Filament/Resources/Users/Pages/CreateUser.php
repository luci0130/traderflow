<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Concerns\SyncesTenantRoles;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use SyncesTenantRoles;

    protected static string $resource = UserResource::class;

    /**
     * @var array<int, string>
     */
    protected array $roles = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->roles = $data['roles'] ?? [];
        unset($data['roles']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;
        $this->syncGlobalRoles($user, $this->roles);
    }
}
