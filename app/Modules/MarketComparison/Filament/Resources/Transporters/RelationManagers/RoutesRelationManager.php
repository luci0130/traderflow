<?php

namespace App\Modules\MarketComparison\Filament\Resources\Transporters\RelationManagers;

use App\Modules\MarketComparison\Models\TransportRoute;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RoutesRelationManager extends RelationManager
{
    protected static string $relationship = 'routes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Routes');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('origin')
                    ->required()
                    ->placeholder(__('Spania'))
                    ->maxLength(255),
                TextInput::make('destination')
                    ->required()
                    ->placeholder(__('Turda'))
                    ->maxLength(255),
                TextInput::make('distance_km')
                    ->label(__('Distance (km)'))
                    ->numeric()
                    ->minValue(0),
                TextInput::make('estimated_cost')
                    ->label(__('Estimated cost'))
                    ->helperText(__('Leave empty to calculate from distance and cost per km.'))
                    ->numeric()
                    ->minValue(0),
                TextInput::make('lead_time_days')
                    ->label(__('Lead time (days)'))
                    ->numeric()
                    ->minValue(0),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('origin')
            ->columns([
                TextColumn::make('origin')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('destination')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('distance_km')
                    ->label(__('Distance (km)'))
                    ->numeric(decimalPlaces: 0)
                    ->placeholder('-'),
                TextColumn::make('resolved_cost')
                    ->label(__('Cost'))
                    ->state(fn (TransportRoute $record): ?float => $record->resolved_cost)
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (TransportRoute $record): string => ' '.($record->transporter?->currency ?? ''))
                    ->placeholder('-'),
                TextColumn::make('lead_time_days')
                    ->label(__('Lead time'))
                    ->suffix(' '.__('days'))
                    ->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make(),
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
