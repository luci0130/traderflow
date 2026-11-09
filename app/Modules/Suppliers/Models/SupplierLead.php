<?php

namespace App\Modules\Suppliers\Models;

use App\Models\User;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Supermarkets\Models\SupermarketProductCompany;
use Database\Factories\SupplierLeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SupplierLead extends Model
{
    /** @use HasFactory<SupplierLeadFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'country',
        'website',
        'email',
        'phone',
        'notes',
        'created_by',
        'supermarket_product_id',
        'converted_supplier_id',
        'converted_at',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $lead): void {
            if ($lead->wasChanged('supermarket_product_id') || $lead->wasRecentlyCreated) {
                if ($lead->supermarket_product_id !== null) {
                    $lead->supermarketProducts()->syncWithoutDetaching([$lead->supermarket_product_id]);
                }
            }

            if ($lead->converted_supplier_id !== null && ($lead->wasChanged('converted_supplier_id') || $lead->wasRecentlyCreated)) {
                DB::table('supermarket_product_companies')->where('supplier_lead_id', $lead->getKey())
                    ->update(['supplier_id' => $lead->converted_supplier_id]);
                $lead->supermarketPrices()->update(['supplier_id' => $lead->converted_supplier_id]);
            }
        });
    }

    public function supermarketProducts(): BelongsToMany
    {
        return $this->belongsToMany(SupermarketProduct::class, 'supermarket_product_companies', 'supplier_lead_id')
            ->using(SupermarketProductCompany::class)->withTimestamps();
    }

    public function supermarketPrices(): HasMany
    {
        return $this->hasMany(SupermarketPrice::class);
    }

    protected static function newFactory(): SupplierLeadFactory
    {
        return SupplierLeadFactory::new();
    }

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supermarketProduct(): BelongsTo
    {
        return $this->belongsTo(SupermarketProduct::class);
    }

    public function convertedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'converted_supplier_id');
    }

    public function isConverted(): bool
    {
        return $this->converted_supplier_id !== null;
    }
}
