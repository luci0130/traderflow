<?php

namespace App\Modules\Suppliers\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Documents\Models\Document;
use App\Modules\MarketComparison\Models\SupplierCostDefault;
use App\Modules\MarketComparison\Models\SupplierReview;
use App\Modules\Producers\Models\ProducerOrder;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Support\Tenancy\ActiveTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Supplier extends Model
{
    use HasFactory;

    public const MANAGEMENT_MODE_OPERATOR = 'operator_managed';

    public const MANAGEMENT_MODE_SELF = 'self_managed';

    public const OFFERS_STATUS_NONE = 'none';

    public const OFFERS_STATUS_EXPIRED = 'expired';

    public const OFFERS_STATUS_MIXED = 'mixed';

    public const OFFERS_STATUS_VALID = 'valid';

    protected $guarded = [];

    protected $attributes = [
        'management_mode' => self::MANAGEMENT_MODE_OPERATOR,
        'is_producer' => false,
        'status' => 'active',
        'default_currency' => 'EUR',
        'invoice_starting_number' => 1,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('active_tenant', function (Builder $query): void {
            if (auth()->user()?->isSuperAdmin()) {
                return;
            }

            $tenantId = app(ActiveTenant::class)->id();

            if ($tenantId === null) {
                return;
            }

            $query->where(function (Builder $query) use ($tenantId): void {
                $query
                    ->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId)
                    ->orWhereNull($query->getModel()->qualifyColumn('tenant_id'));
            });
        });
    }

    protected function casts(): array
    {
        return [
            'is_producer' => 'boolean',
            'invoice_starting_number' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'producer_id');
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'producer_id');
    }

    public function producerOrders(): HasMany
    {
        return $this->hasMany(ProducerOrder::class, 'producer_id');
    }

    public function customerOfferItems(): HasMany
    {
        return $this->hasMany(CustomerOfferItem::class);
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function costDefault(): HasOne
    {
        return $this->hasOne(SupplierCostDefault::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SupplierReview::class);
    }

    protected function averageRating(): Attribute
    {
        return Attribute::get(function (): ?float {
            $value = $this->getAttributeFromArray('reviews_avg_rating');

            $average = $value !== null
                ? (float) $value
                : $this->reviews()->avg('rating');

            return $average !== null ? round((float) $average, 2) : null;
        });
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    protected function offersStatus(): Attribute
    {
        return Attribute::get(function (): string {
            $total = $this->resolveSupplierProductsCount();
            $valid = $this->resolveValidSupplierProductsCount();

            if ($total === 0) {
                return self::OFFERS_STATUS_NONE;
            }

            if ($valid === 0) {
                return self::OFFERS_STATUS_EXPIRED;
            }

            if ($valid === $total) {
                return self::OFFERS_STATUS_VALID;
            }

            return self::OFFERS_STATUS_MIXED;
        });
    }

    private function resolveSupplierProductsCount(): int
    {
        $value = $this->getAttributeFromArray('supplier_products_count');

        return $value !== null
            ? (int) $value
            : $this->supplierProducts()->count();
    }

    private function resolveValidSupplierProductsCount(): int
    {
        $value = $this->getAttributeFromArray('valid_supplier_products_count');

        return $value !== null
            ? (int) $value
            : $this->supplierProducts()
                ->where('status', 'active')
                ->whereDate('valid_until', '>=', today())
                ->count();
    }
}
