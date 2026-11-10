<?php

namespace App\Modules\Producers\Filament\Pages;

use App\Modules\Producers\Models\Producer;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierContact;
use Filament\Actions\Action;
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

    public function boot(): void
    {
        if (! in_array(app()->getLocale(), ['en', 'ro'], true)) {
            app()->setLocale('en');
        }
    }

    public function switchLanguage(string $locale): void
    {
        abort_unless(in_array($locale, ['en', 'ro'], true), 422);

        session()->put('locale', $locale);
        app()->setLocale($locale);
        $this->resetValidation();
        $this->dispatch('registration-language-changed', locale: $locale, title: $this->getTitle().' - '.config('malcara.name'));
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label(fn (): string => __('Create Malcara account'));
    }

    public function loginAction(): Action
    {
        return parent::loginAction()
            ->label(fn (): string => __('filament-panels::auth/pages/register.actions.login.label'));
    }

    public function form(Schema $schema): Schema
    {
        $components = [
            TextInput::make('producer_name')
                ->label(fn (): string => __('Company name'))
                ->required()
                ->maxLength(255)
                ->placeholder('Acme Foods SRL'),
            $this->getNameFormComponent()
                ->label(fn (): string => __('Your name'))
                ->placeholder('Jan Kowalski'),
            $this->getEmailFormComponent()
                ->label(fn (): string => __('filament-panels::auth/pages/register.form.email.label'))
                ->placeholder('hello@acmefoods.eu'),
            TextInput::make('phone')
                ->label(fn (): string => __('Phone'))
                ->tel()
                ->maxLength(32),
            TextInput::make('role_in_company')
                ->label(fn (): string => __('Role in company'))
                ->maxLength(255)
                ->placeholder(fn (): string => __('Owner')),
            $this->getPasswordFormComponent()
                ->label(fn (): string => __('filament-panels::auth/pages/register.form.password.label'))
                ->validationAttribute(fn (): string => __('filament-panels::auth/pages/register.form.password.validation_attribute')),
            $this->getPasswordConfirmationFormComponent()
                ->label(fn (): string => __('filament-panels::auth/pages/register.form.password_confirmation.label')),
        ];

        foreach ($components as $component) {
            $component->validationMessages([
                'required' => fn (): string => __('This field is required.'),
                'email' => fn (): string => __('Enter a valid email address.'),
                'unique' => fn (): string => __('An account with this email address already exists.'),
                'max' => fn (): string => __('Use no more than :max characters.'),
                'min' => fn (): string => __('Use at least :min characters.'),
                'same' => fn (): string => __('The passwords do not match.'),
                'password.letters' => fn (): string => __('The password must contain at least one letter.'),
                'password.mixed' => fn (): string => __('The password must contain uppercase and lowercase letters.'),
                'password.numbers' => fn (): string => __('The password must contain at least one number.'),
                'password.symbols' => fn (): string => __('The password must contain at least one symbol.'),
            ]);
        }

        return $schema->components($components);
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
