<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Concerns\SyncesTenantRoles;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use SyncesTenantRoles;

    protected static string $resource = UserResource::class;

    /**
     * @var array<int, string>
     */
    protected array $roles = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;
        $data['roles'] = $this->readGlobalRoles($user);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->roles = $data['roles'] ?? [];
        unset($data['roles']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->record;
        $this->syncGlobalRoles($user, $this->roles);
    }
}
