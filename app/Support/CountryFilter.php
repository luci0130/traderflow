<?php

namespace App\Support;

use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountryFilter
{
    public static function make(string $column = 'country', ?string $relationship = null): SelectFilter
    {
        return SelectFilter::make($relationship === null ? $column : $relationship.'_'.$column)
            ->label(__($column === 'country' ? 'Country' : 'Country of origin'))
            ->searchable()
            ->options(function (Table $table) use ($column, $relationship): array {
                $options = [];

                foreach (self::values($table, $column, $relationship) as $value) {
                    $code = Countries::normalize($value);

                    if ($code !== null) {
                        $options[$code] = Countries::label($code);
                    }
                }

                asort($options);

                return $options;
            })
            ->query(function (Builder $query, array $data, Table $table) use ($column, $relationship): Builder {
                if (blank($data['value'] ?? null)) {
                    return $query;
                }

                $values = array_values(array_filter(self::values($table, $column, $relationship),
                    fn (string $value): bool => Countries::normalize($value) === $data['value']));
                $apply = fn (Builder $query): Builder => $query->whereIn($query->getModel()->qualifyColumn($column), $values);

                return $relationship === null ? $apply($query) : $query->whereHas($relationship, $apply);
            });
    }

    /** @return array<int, string> */
    private static function values(Table $table, string $column, ?string $relationship): array
    {
        $query = clone $table->getQuery();

        if ($relationship !== null) {
            /** @var BelongsTo $relation */
            $relation = $query->getModel()->{$relationship}();
            $query = $relation->getRelated()->newQuery()->whereIn(
                $relation->getQualifiedOwnerKeyName(),
                $query->reorder()->select($relation->getQualifiedForeignKeyName()),
            );
        }

        $column = $query->getModel()->qualifyColumn($column);

        return $query->reorder()->select($column)->setEagerLoads([])->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->pluck($column)->all();
    }
}
