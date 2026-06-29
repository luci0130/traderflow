<?php

namespace App\Modules\MarketComparison\Models;

use App\Modules\Producers\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductCostOverride extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'packaging_cost' => 'decimal:4',
            'transport_cost' => 'decimal:4',
            'commission' => 'decimal:4',
            'profit_margin' => 'decimal:4',
        ];
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
