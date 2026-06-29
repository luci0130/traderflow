<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers;

use App\Modules\Suppliers\Models\SupplierContact;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use STS\FilamentImpersonate\Actions\Impersonate;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected ?string $roleInCompany = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Users');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('phone')->tel()->maxLength(32),
                TextInput::make('role_in_company')
                    ->label(__('Role in company'))
                    ->maxLength(255)
                    ->dehydrated(false),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('email_verified_at')->dateTime()->toggleable()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $this->roleInCompany = $data['role_in_company'] ?? null;
                        unset($data['role_in_company']);

                        $data['producer_id'] = $this->getOwnerRecord()->getKey();

                        return $data;
                    })
                    ->after(function ($record): void {
                        SupplierContact::updateOrCreate(
                            [
                                'supplier_id' => $this->getOwnerRecord()->getKey(),
                                'user_id' => $record->getKey(),
                            ],
                            [
                                'name' => $record->name,
                                'role_in_company' => $this->roleInCompany ?? null,
                                'email' => $record->email,
                                'phone' => $record->phone,
                                'is_primary' => false,
                                'can_access_portal' => true,
                            ],
                        );

                        \setPermissionsTeamId(null);
                        $record->assignRole('producer');
                    }),
            ])
            ->recordActions([
                Impersonate::make()
                    ->guard('web')
                    ->redirectTo(fn (): string => Filament::getPanel('producer')->getUrl()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
