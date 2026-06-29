<?php

namespace App\Modules\Customers\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Documents\Models\Document;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Customer extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'active',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function customerOffers(): HasMany
    {
        return $this->hasMany(CustomerOffer::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CustomerLocation::class);
    }

    /**
     * Globally shared records (e.g. supermarkets) carry no tenant_id and are
     * visible to every tenant.
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->qualifyColumn('tenant_id'));
    }

    public function scopeVisibleToTenant(Builder $query, ?int $tenantId): Builder
    {
        $query->withoutGlobalScope('active_tenant');

        if ($tenantId === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($tenantId): void {
            $query
                ->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId)
                ->orWhereNull($query->getModel()->qualifyColumn('tenant_id'));
        });
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SupermarketPrice::class, 'supermarket_id');
    }

    public function pricePhotos(): HasMany
    {
        return $this->hasMany(SupermarketPricePhoto::class, 'supermarket_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            SupermarketProduct::class,
            'supermarket_prices',
            'supermarket_id',
            'supermarket_product_id',
        )->distinct();
    }
}
