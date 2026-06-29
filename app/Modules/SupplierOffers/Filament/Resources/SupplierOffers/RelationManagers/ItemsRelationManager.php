<?php

namespace App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\RelationManagers;

use App\Modules\Products\Models\Product;
use App\Modules\Units\Models\Unit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('tenant_id')
                    ->default(fn (): int => $this->getOwnerRecord()->tenant_id),
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn (): array => Product::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable(),
                Select::make('unit_id')
                    ->label('Unit')
                    ->options(fn (): array => Unit::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                TextInput::make('quantity')
                    ->numeric(),
                TextInput::make('purchase_price')
                    ->numeric()
                    ->required(),
                Select::make('currency')
                    ->options([
                        'EUR' => 'EUR',
                        'RON' => 'RON',
                        'USD' => 'USD',
                        'GBP' => 'GBP',
                    ])
                    ->default('EUR')
                    ->required(),
                DatePicker::make('availability_date'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->searchable(),
                TextColumn::make('unit.symbol'),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('purchase_price')
                    ->money(fn ($record): string => $record->currency ?? 'EUR')
                    ->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('availability_date')->date()->sortable(),
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
