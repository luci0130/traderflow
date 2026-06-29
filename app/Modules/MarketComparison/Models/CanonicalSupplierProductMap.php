<?php

namespace App\Modules\MarketComparison\Models;

use App\Modules\Producers\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanonicalSupplierProductMap extends Model
{
    protected $table = 'canonical_supplier_product';

    protected $guarded = [];

    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
