<?php

namespace App\Modules\Dashboard\Filament\Widgets;

use App\Modules\Suppliers\Models\SupplierLead;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SupplierLeadsToConvertWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isPurchasingAgent() ?? false;
    }

    public function table(Table $table): Table
    {
        // Supplier leads are a global table (no tenant scope).
        return $table
            ->heading(__('Supplier leads to convert'))
            ->query(fn (): Builder => SupplierLead::query()
                ->whereNull('converted_supplier_id')
                ->latest())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('country')->placeholder('—'),
                TextColumn::make('email')->placeholder('—'),
                TextColumn::make('created_at')->date()->placeholder('—'),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25]);
    }
}
