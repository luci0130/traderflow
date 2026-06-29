<?php

namespace App\Modules\Units\Models;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function supplierOfferItems(): HasMany
    {
        return $this->hasMany(SupplierOfferItem::class);
    }

    public function customerOfferItems(): HasMany
    {
        return $this->hasMany(CustomerOfferItem::class);
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }
}
