<?php

namespace App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages;

use App\Modules\ProductCategories\Filament\Resources\ProductCategories\ProductCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProductCategories extends ListRecords
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tree')
                ->label('Tree')
                ->icon(Heroicon::OutlinedQueueList)
                ->url(ProductCategoryTree::getUrl()),
            CreateAction::make(),
        ];
    }
}
