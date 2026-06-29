<?php

namespace App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages;

use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\CanonicalProductResource;
use App\Modules\MarketComparison\Services\CanonicalAutoGrouper;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCanonicalProducts extends ListRecords
{
    protected static string $resource = CanonicalProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('autoGroup')
                ->label(__('Auto-group products'))
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('Auto-group products'))
                ->modalDescription(__('Creates canonical products keyed on name + category + packaging and maps every supplier and supermarket product that is not yet grouped. Existing groups are left untouched.'))
                ->action(function (CanonicalAutoGrouper $grouper): void {
                    $stats = $grouper->group();

                    Notification::make()
                        ->title(__('Auto-grouping complete'))
                        ->body(__(':canonicals new canonical products · :supplier supplier and :supermarket supermarket products mapped.', [
                            'canonicals' => $stats['canonicals_created'],
                            'supplier' => $stats['supplier_mapped'],
                            'supermarket' => $stats['supermarket_mapped'],
                        ]))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
