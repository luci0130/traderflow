<?php

namespace App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Filament\Actions\ImportSupplierProductsAction;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSupplierProducts extends ListRecords
{
    protected static string $resource = SupplierProductResource::class;

    protected function applyGroupingToTableQuery(Builder $query): Builder
    {
        $query = parent::applyGroupingToTableQuery($query);

        if (! $this->getTableGrouping()) {
            return $query;
        }

        $orders = $query->getQuery()->orders ?? [];
        $bindings = $query->getQuery()->getRawBindings()['order'];
        $supplier = new Supplier;
        $supplierColumn = (new SupplierProduct)->qualifyColumn('producer_id');

        $query->reorder()
            ->orderBy(Supplier::query()->select('name')->whereColumn($supplier->getQualifiedKeyName(), $supplierColumn))
            ->orderBy($supplierColumn);
        $query->getQuery()->orders = array_merge($query->getQuery()->orders, $orders);
        $query->getQuery()->addBinding($bindings, 'order');

        return $query;
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportSupplierProductsAction::make(),
            CreateAction::make(),
        ];
    }
}
