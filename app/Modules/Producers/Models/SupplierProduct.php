<?php

namespace App\Modules\Producers\Models;

use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Models\SupplierProductCostOverride;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\CategoryImages;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierProduct extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'quantity_available' => 'decimal:4',
            'package_size' => 'decimal:4',
            'min_quantity_value' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'is_bio' => 'boolean',
        ];
    }

    /**
     * The image path to display: the product's own picture, or its category's
     * (matched by the free-text `category` name) as a fallback. Both live on the
     * `public` disk.
     */
    protected function displayImagePath(): Attribute
    {
        return Attribute::get(fn (): ?string => filled($this->image_path)
            ? $this->image_path
            : app(CategoryImages::class)->pathFor($this->category));
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    /**
     * The owning supplier, resolved without the producer (is_producer) scope so
     * it also matches tenant-managed suppliers, not only producer-portal accounts.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'producer_id');
    }

    public function packagingMethod(): BelongsTo
    {
        return $this->belongsTo(PackagingMethod::class);
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function customerOfferItems(): HasMany
    {
        return $this->hasMany(CustomerOfferItem::class);
    }

    public function costOverride(): HasOne
    {
        return $this->hasOne(SupplierProductCostOverride::class);
    }

    public function canonicalProducts(): BelongsToMany
    {
        return $this->belongsToMany(CanonicalProduct::class, 'canonical_supplier_product')
            ->withTimestamps();
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SupplierProductPrice::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Normalize the origin onto the canonical ISO country code regardless of how
     * it was entered (form code, scraped Romanian name, etc.).
     */
    protected function countryOfOrigin(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => Countries::normalize($value));
    }

    protected function isOfferValid(): Attribute
    {
        return Attribute::get(fn (): bool => $this->status === 'active'
            && $this->valid_until !== null
            && $this->valid_until->gte(now()->startOfDay()));
    }
}
