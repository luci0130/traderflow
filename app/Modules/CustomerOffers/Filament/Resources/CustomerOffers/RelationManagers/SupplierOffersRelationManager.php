<?php

namespace App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers;

use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\SupplierOfferResource;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Support\StatusColors;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list of the supplier offers generated alongside this customer offer
 * (one per supplier sourced). Each row links out to the full supplier offer.
 */
class SupplierOffersRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierOffers';

    protected static ?string $title = 'Supplier offers';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('offer_number')
            ->columns([
                TextColumn::make('offer_number')
                    ->label(__('Offer number'))
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label(__('Products'))
                    ->counts('items')
                    ->badge(),
                TextColumn::make('received_at')
                    ->label(__('Received'))
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label(__('Valid until'))
                    ->date()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): array => StatusColors::badge($state))
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (SupplierOffer $record): string => SupplierOfferResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
            ]);
    }
}
