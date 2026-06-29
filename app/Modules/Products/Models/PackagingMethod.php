<?php

namespace App\Modules\Products\Models;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Database\Factories\PackagingMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackagingMethod extends Model
{
    /** @use HasFactory<PackagingMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function newFactory(): PackagingMethodFactory
    {
        return PackagingMethodFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('is_active'), true);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function supermarketProducts(): HasMany
    {
        return $this->hasMany(SupermarketProduct::class);
    }
}
