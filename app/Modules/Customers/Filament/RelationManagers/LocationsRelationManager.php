<?php

namespace App\Modules\Customers\Filament\RelationManagers;

use App\Modules\Customers\Enums\CustomerLocationType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Locations');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->locations()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('tenant_id')
                    ->default(fn (): ?int => $this->getOwnerRecord()->tenant_id),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->label(__('Type'))
                    ->options(CustomerLocationType::options())
                    ->default(CustomerLocationType::Supermarket->value)
                    ->required(),
                TextInput::make('country')
                    ->label(__('Country'))
                    ->maxLength(255),
                TextInput::make('county')
                    ->label(__('Judet'))
                    ->maxLength(255),
                TextInput::make('city')
                    ->label(__('City'))
                    ->maxLength(255),
                Textarea::make('address')
                    ->label(__('Address'))
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->columnSpanFull(),
                Section::make(__('Billing details'))
                    ->description(__('Fill in when this location invoices as its own legal entity.'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_separate_legal_entity')
                            ->label(__('Separate legal entity'))
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('legal_name')
                            ->label(__('Legal name'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_separate_legal_entity')),
                        TextInput::make('fiscal_code')
                            ->label(__('Fiscal code (CIF/CUI)'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_separate_legal_entity')),
                        TextInput::make('bank_name')
                            ->label(__('Bank name'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_separate_legal_entity')),
                        TextInput::make('bank_account')
                            ->label(__('Bank account (IBAN)'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_separate_legal_entity')),
                    ]),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (CustomerLocationType|string|null $state): ?string => $state instanceof CustomerLocationType ? $state->label() : $state)
                    ->sortable(),
                TextColumn::make('country')
                    ->label(__('Country'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('county')
                    ->label(__('Judet'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('city')
                    ->label(__('City'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('address')
                    ->label(__('Address'))
                    ->searchable()
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(CustomerLocationType::options()),
                SelectFilter::make('city')
                    ->label(__('City'))
                    ->options(fn (): array => $this->getOwnerRecord()->locations()
                        ->whereNotNull('city')
                        ->where('city', '!=', '')
                        ->distinct()
                        ->orderBy('city')
                        ->pluck('city', 'city')
                        ->all())
                    ->searchable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['tenant_id'] = $this->getOwnerRecord()->tenant_id;

                        return $data;
                    }),
            ])
            ->recordActions([
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
