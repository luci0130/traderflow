<?php

namespace App\Modules\ProductCategories\Models;

use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Product categories are a single global taxonomy shared across all tenants
 * (no tenant scoping), like the supplier and supermarket catalogs.
 */
class ProductCategory extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleted(function (ProductCategory $category): void {
            if (filled($category->image_path)) {
                Storage::disk('public')->delete($category->image_path);
            }
        });
    }

    public function scopeVisibleToTenant(Builder $query, ?int $tenantId): Builder
    {
        if ($tenantId === null) {
            return $query->whereNull($query->getModel()->qualifyColumn('tenant_id'));
        }

        return $query->where(function (Builder $query) use ($tenantId): void {
            $query
                ->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId)
                ->orWhereNull($query->getModel()->qualifyColumn('tenant_id'));
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
