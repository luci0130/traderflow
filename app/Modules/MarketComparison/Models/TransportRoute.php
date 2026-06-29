<?php

namespace App\Modules\MarketComparison\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportRoute extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:4',
            'estimated_cost' => 'decimal:4',
            'lead_time_days' => 'integer',
        ];
    }

    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Transporter::class);
    }

    /**
     * The explicit estimated cost when set, otherwise distance multiplied by the
     * transporter's cost per km.
     */
    protected function resolvedCost(): Attribute
    {
        return Attribute::get(function (): ?float {
            if ($this->estimated_cost !== null) {
                return (float) $this->estimated_cost;
            }

            if ($this->distance_km === null || $this->transporter?->cost_per_km === null) {
                return null;
            }

            return round((float) $this->distance_km * (float) $this->transporter->cost_per_km, 4);
        });
    }
}
