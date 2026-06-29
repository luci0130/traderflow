<?php

namespace App\Modules\Producers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_quantity_value' => 'decimal:4',
            'unit_price' => 'decimal:4',
        ];
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
