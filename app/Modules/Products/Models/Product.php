<?php

namespace App\Modules\Products\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleted(function (Product $product): void {
            if (filled($product->image_path)) {
                Storage::disk('public')->delete($product->image_path);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
