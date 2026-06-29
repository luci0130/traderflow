<?php

namespace App\Modules\MarketComparison\Models;

use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanonicalSupermarketProductMap extends Model
{
    protected $table = 'canonical_supermarket_product';

    protected $guarded = [];

    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    public function supermarketProduct(): BelongsTo
    {
        return $this->belongsTo(SupermarketProduct::class);
    }
}
