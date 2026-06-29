<?php

namespace App\Modules\NumberSequences\Concerns;

use App\Modules\NumberSequences\Services\NumberSequenceGenerator;
use App\Support\Tenancy\ActiveTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-fills a model's document number from its tenant's number sequence when
 * the number is left blank on create. A manually provided number is kept as-is
 * (override) and does not consume the sequence.
 *
 * Implementers define the sequence key and the column that stores the number.
 */
trait HasNumberSequence
{
    public static function bootHasNumberSequence(): void
    {
        static::creating(function (Model $model): void {
            /** @var Model&self $model */
            $column = $model->numberSequenceColumn();

            if (filled($model->{$column})) {
                return;
            }

            $tenantId = $model->tenant_id ?? app(ActiveTenant::class)->id();

            if ($tenantId === null) {
                return;
            }

            $model->{$column} = app(NumberSequenceGenerator::class)->next(
                (int) $tenantId,
                $model->numberSequenceKey(),
            );
        });
    }

    abstract public function numberSequenceKey(): string;

    public function numberSequenceColumn(): string
    {
        return 'number';
    }
}
