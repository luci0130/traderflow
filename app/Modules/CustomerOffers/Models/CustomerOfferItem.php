<?php

namespace App\Modules\CustomerOffers\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Modules\CustomerOffers\Observers\CustomerOfferItemObserver;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\Product;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(CustomerOfferItemObserver::class)]
class CustomerOfferItem extends Model
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
            'quantity' => 'decimal:4',
            'purchase_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'margin_value' => 'decimal:4',
            'margin_percent' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customerOffer(): BelongsTo
    {
        return $this->belongsTo(CustomerOffer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierOfferItem(): BelongsTo
    {
        return $this->belongsTo(SupplierOfferItem::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
