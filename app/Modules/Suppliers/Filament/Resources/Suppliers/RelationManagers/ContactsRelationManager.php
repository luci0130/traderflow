<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use STS\FilamentImpersonate\Actions\Impersonate;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Contacts');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label(__('User account'))
                    ->options(fn (): array => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if ($state === null) {
                            return;
                        }

                        $user = User::query()->find($state);

                        if ($user === null) {
                            return;
                        }

                        $set('name', $user->name);
                        $set('email', $user->email);
                        $set('phone', $user->phone);
                        $set('can_access_portal', true);
                    }),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->maxLength(255),
                TextInput::make('role_in_company')
                    ->label(__('Role in company'))
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label(__('Phone'))
                    ->tel()
                    ->maxLength(32),
                Toggle::make('is_primary')
                    ->label(__('Primary contact'))
                    ->default(false),
                Toggle::make('can_access_portal')
                    ->label(__('Portal access'))
                    ->default(false),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('role_in_company')->label(__('Role'))->searchable()->toggleable(),
                TextColumn::make('email')->label(__('Email'))->searchable(),
                TextColumn::make('phone')->label(__('Phone'))->searchable()->toggleable(),
                IconColumn::make('is_primary')->label(__('Primary'))->boolean(),
                IconColumn::make('can_access_portal')->label(__('Portal'))->boolean(),
                TextColumn::make('user.name')->label(__('User'))->toggleable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Impersonate::make()
                    ->guard('web')
                    ->impersonateRecord(fn ($record) => $record->user)
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
