<?php

namespace App\Modules\Products\Filament\Resources\PackagingMethods;

use App\Modules\Products\Filament\Resources\PackagingMethods\Pages\CreatePackagingMethod;
use App\Modules\Products\Filament\Resources\PackagingMethods\Pages\EditPackagingMethod;
use App\Modules\Products\Filament\Resources\PackagingMethods\Pages\ListPackagingMethods;
use App\Modules\Products\Models\PackagingMethod;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PackagingMethodResource extends Resource
{
    protected static ?string $model = PackagingMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Packaging method');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Packaging methods');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('sort_order')
                    ->label(__('Sort order'))
                    ->numeric()
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->label(__('Sort'))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackagingMethods::route('/'),
            'create' => CreatePackagingMethod::route('/create'),
            'edit' => EditPackagingMethod::route('/{record}/edit'),
        ];
    }
}
