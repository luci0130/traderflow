<?php

namespace App\Models;

use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Models\Document;
use App\Modules\Emails\Models\Email;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\TenantSettings\Models\CustomField;
use App\Modules\TenantSettings\Models\CustomFieldValue;
use App\Modules\TenantSettings\Models\TenantSetting;
use App\Modules\Units\Models\Unit;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model implements HasCurrentTenantLabel, HasName
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getCurrentTenantLabel(): string
    {
        return 'Active tenant';
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function supplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }

    public function customerOffers(): HasMany
    {
        return $this->hasMany(CustomerOffer::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class);
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
