<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CreationDateTable
{
    public static function configure(Table $table, string $column = 'created_at'): Table
    {
        return $table
            ->groups([
                Group::make('created_week')
                    ->column($column)
                    ->label(__('By week'))
                    ->titlePrefixedWithLabel(false)
                    ->collapsible()
                    ->getKeyFromRecordUsing(fn (Model $record): ?string => filled($record->{$column})
                        ? CarbonImmutable::parse($record->{$column})->startOfWeek(CarbonInterface::MONDAY)->toDateString()
                        : null)
                    ->getTitleFromRecordUsing(fn (Model $record): string => filled($record->{$column})
                        ? static::weekLabel($record->{$column})
                        : __('Without compared prices'))
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => static::orderByWeek($query, $column, $direction))
                    ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => $key === null
                        ? $query->whereNull($column)
                        : static::apply($query, ['created_week' => ['date' => $key]], $column)),
            ])
            ->defaultGroup('created_week')
            ->groupingSettingsInDropdownOnDesktop()
            ->groupRecordsTriggerAction(fn (Action $action): Action => $action
                ->button()
                ->label(fn (): string => $table->getGrouping() ? __('By week') : __('Table view')));
    }

    /**
     * @return array<Filter>
     */
    public static function filters(bool $applyToQuery = true): array
    {
        return [
            Filter::make('created_at')
                ->label(__('Creation date'))
                ->schema([
                    DatePicker::make('from')->label(__('Created from')),
                    DatePicker::make('until')->label(__('Created until')),
                ])
                ->query(fn (Builder $query, array $data): Builder => $applyToQuery
                    ? static::apply($query, ['created_at' => $data])
                    : $query)
                ->indicateUsing(fn (array $data): array => array_filter([
                    filled($data['from'] ?? null) ? __('Created from').': '.CarbonImmutable::parse($data['from'])->format('d.m.Y') : null,
                    filled($data['until'] ?? null) ? __('Created until').': '.CarbonImmutable::parse($data['until'])->format('d.m.Y') : null,
                ])),
            Filter::make('created_week')
                ->label(__('Creation week'))
                ->schema([
                    DatePicker::make('date')
                        ->label(__('Creation week'))
                        ->helperText(__('Choose any date in the week (Monday–Sunday).')),
                ])
                ->query(fn (Builder $query, array $data): Builder => $applyToQuery
                    ? static::apply($query, ['created_week' => $data])
                    : $query)
                ->indicateUsing(fn (array $data): ?string => filled($data['date'] ?? null)
                    ? __('Creation week').': '.static::weekLabel($data['date'])
                    : null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function bounds(?array $filters): array
    {
        $from = filled($filters['created_at']['from'] ?? null)
            ? CarbonImmutable::parse($filters['created_at']['from'])->startOfDay() : null;
        $until = filled($filters['created_at']['until'] ?? null)
            ? CarbonImmutable::parse($filters['created_at']['until'])->addDay()->startOfDay() : null;

        if (filled($filters['created_week']['date'] ?? null)) {
            $weekStart = CarbonImmutable::parse($filters['created_week']['date'])->startOfWeek(CarbonInterface::MONDAY);
            $weekEnd = $weekStart->addWeek();
            $from = $from === null || $weekStart->greaterThan($from) ? $weekStart : $from;
            $until = $until === null || $weekEnd->lessThan($until) ? $weekEnd : $until;
        }

        return [$from, $until];
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function apply(Builder $query, ?array $filters, string $column = 'created_at'): Builder
    {
        [$from, $until] = static::bounds($filters);
        $column = $query->getModel()->qualifyColumn($column);

        return $query
            ->when($from, fn (Builder $query): Builder => $query->where($column, '>=', $from))
            ->when($until, fn (Builder $query): Builder => $query->where($column, '<', $until));
    }

    /**
     * @param  array<int, string|null>  $dates
     */
    public static function withComparedDates(Builder $query, array $dates, bool $onlyWithPrices): Builder
    {
        $model = $query->getModel();
        $expression = 'CASE '.$query->getQuery()->getGrammar()->wrap($model->getQualifiedKeyName());
        $bindings = [];

        foreach ($dates as $id => $date) {
            $expression .= ' WHEN ? THEN ?';
            array_push($bindings, $id, $date);
        }

        $expression = $dates === [] ? 'NULL' : $expression.' ELSE NULL END';
        $query->select($model->qualifyColumn('*'))->selectRaw($expression.' AS comparison_created_at', $bindings);
        $result = $model->newQuery()->fromSub($query, $model->getTable());

        return $result->when($onlyWithPrices, fn (Builder $query): Builder => $query->whereNotNull('comparison_created_at'));
    }

    public static function weekLabel(CarbonInterface|string $date): string
    {
        $start = CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY);

        return $start->format('d.m.Y').' – '.$start->addDays(6)->format('d.m.Y');
    }

    private static function weekExpression(Builder $query, string $column): string
    {
        $column = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($column));

        return match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)",
            'pgsql' => "DATE_TRUNC('week', {$column}::timestamp)",
            default => "DATE({$column}, '-6 days', 'weekday 1')",
        };
    }

    private static function orderByWeek(Builder $query, string $column, string $direction): Builder
    {
        $orders = $query->getQuery()->orders ?? [];
        $bindings = $query->getQuery()->getRawBindings()['order'];
        $query->reorder()->orderByRaw(static::weekExpression($query, $column).' '.($direction === 'desc' ? 'DESC' : 'ASC'));
        $query->getQuery()->orders = array_merge($query->getQuery()->orders, $orders);
        $query->getQuery()->addBinding($bindings, 'order');

        return $query;
    }
}
