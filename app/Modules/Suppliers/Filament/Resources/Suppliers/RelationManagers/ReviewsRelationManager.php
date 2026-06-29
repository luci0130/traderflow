<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Reviews');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $average = $ownerRecord->average_rating;

        return $average !== null ? number_format($average, 1).' ★' : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('rating')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state))
                    ->color('warning')
                    ->sortable(),
                TextColumn::make('comment')
                    ->label(__('Comment'))
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('producerOrder.order_number')
                    ->label(__('Order'))
                    ->placeholder('-'),
                TextColumn::make('reviewer.name')
                    ->label(__('By'))
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
