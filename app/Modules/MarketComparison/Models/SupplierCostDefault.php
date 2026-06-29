<?php

namespace App\Modules\MarketComparison\Models;

use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCostDefault extends Model
{
    public const COST_BASIS_PER_UNIT = 'per_unit';

    public const COST_BASIS_PERCENT = 'percent';

    protected $guarded = [];

    protected $attributes = [
        'cost_basis' => self::COST_BASIS_PER_UNIT,
        'currency' => 'EUR',
    ];

    protected function casts(): array
    {
        return [
            'packaging_cost' => 'decimal:4',
            'transport_cost' => 'decimal:4',
            'commission' => 'decimal:4',
            'profit_margin' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withoutGlobalScope('active_tenant');
    }
}
