<?php

namespace App\Modules\Producers\Filament\Pages;

use App\Modules\Producers\Models\Producer;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierContact;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Spatie\Permission\Models\Role;

use function setPermissionsTeamId;

class RegisterProducer extends Register
{
    protected string $view = 'producers.filament.pages.register-producer';

    protected static string $layout = 'producers.filament.layouts.split-auth';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('producer_name')
                    ->label(__('Company name'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Acme Foods SRL'),
                $this->getNameFormComponent()
                    ->label(__('Your name'))
                    ->placeholder('Jan Kowalski'),
                $this->getEmailFormComponent()
                    ->placeholder('hello@acmefoods.eu'),
                TextInput::make('phone')
                    ->label(__('Phone'))
                    ->tel()
                    ->maxLength(32),
                TextInput::make('role_in_company')
                    ->label(__('Role in company'))
                    ->maxLength(255)
                    ->placeholder(__('Owner')),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            $producer = Producer::create([
                'name' => $data['producer_name'],
                'email' => $data['email'],
                'status' => 'active',
                'management_mode' => Supplier::MANAGEMENT_MODE_SELF,
                'is_producer' => true,
            ]);

            $roleInCompany = $data['role_in_company'] ?? null;
            unset($data['producer_name']);
            unset($data['role_in_company']);

            /** @var Model $user */
            $user = $this->getUserModel()::create($data);
            $user->producer_id = $producer->getKey();
            $user->save();

            SupplierContact::create([
                'supplier_id' => $producer->getKey(),
                'user_id' => $user->getKey(),
                'name' => $user->name,
                'role_in_company' => $roleInCompany,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_primary' => true,
                'can_access_portal' => true,
            ]);

            setPermissionsTeamId(null);
            Role::firstOrCreate(['name' => 'producer', 'guard_name' => 'web', 'tenant_id' => null]);
            $user->assignRole('producer');

            return $user;
        });
    }
}
