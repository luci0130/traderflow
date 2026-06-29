<?php

namespace App\Modules\MarketComparison\Filament\Resources\Transporters;

use App\Modules\MarketComparison\Filament\Resources\Transporters\Pages\CreateTransporter;
use App\Modules\MarketComparison\Filament\Resources\Transporters\Pages\EditTransporter;
use App\Modules\MarketComparison\Filament\Resources\Transporters\Pages\ListTransporters;
use App\Modules\MarketComparison\Filament\Resources\Transporters\RelationManagers\RoutesRelationManager;
use App\Modules\MarketComparison\Models\Transporter;
use App\Support\StatusColors;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class TransporterResource extends Resource
{
    protected static ?string $model = Transporter::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Entities';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Transporter');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Transporters');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Entities');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('country')
                            ->label(__('Country'))
                            ->maxLength(255),
                        TextInput::make('county')
                            ->label(__('Judet'))
                            ->maxLength(255),
                        TextInput::make('city')
                            ->label(__('City'))
                            ->maxLength(255),
                        TextInput::make('cost_per_km')
                            ->label(__('Approx. cost per km'))
                            ->numeric()
                            ->minValue(0),
                        Select::make('currency')
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP'])
                            ->default('EUR')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'active' => __('Active'),
                                'inactive' => __('Inactive'),
                            ])
                            ->default('active')
                            ->required(),
                        Textarea::make('notes')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('email')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('city')
                    ->label(__('City'))
                    ->searchable()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('county')
                    ->label(__('Judet'))
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_per_km')
                    ->label(__('Cost / km'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (Transporter $record): string => ' '.$record->currency)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('routes_count')
                    ->label(__('Routes'))
                    ->counts('routes')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): array => StatusColors::badge($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => __('Active'),
                        'inactive' => __('Inactive'),
                    ]),
                SelectFilter::make('city')
                    ->label(__('City'))
                    ->options(fn (): array => Transporter::query()
                        ->whereNotNull('city')
                        ->where('city', '!=', '')
                        ->distinct()
                        ->orderBy('city')
                        ->pluck('city', 'city')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            RoutesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransporters::route('/'),
            'create' => CreateTransporter::route('/create'),
            'edit' => EditTransporter::route('/{record}/edit'),
        ];
    }
}
