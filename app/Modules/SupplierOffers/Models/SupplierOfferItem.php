<?php

namespace App\Modules\SupplierOffers\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Products\Models\Product;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierOfferItem extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'availability_date' => 'date',
            'quantity' => 'decimal:4',
            'purchase_price' => 'decimal:4',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplierOffer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function customerOfferItems(): HasMany
    {
        return $this->hasMany(CustomerOfferItem::class);
    }
}
