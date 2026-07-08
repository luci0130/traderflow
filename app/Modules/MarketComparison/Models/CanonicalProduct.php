<?php

namespace App\Modules\MarketComparison\Models;

use App\Models\User;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Support\CategoryImages;
use App\Support\Countries;
use Database\Factories\CanonicalProductFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CanonicalProduct extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'package_size' => 'decimal:4',
        ];
    }

    protected static function newFactory(): CanonicalProductFactory
    {
        return CanonicalProductFactory::new();
    }

    protected static function booted(): void
    {
        // Keep the denormalized label in sync with the structured packaging fields.
        static::saving(function (CanonicalProduct $product): void {
            $product->packaging_variant = $product->composePackagingVariant();
        });
    }

    /**
     * Normalize the origin onto the canonical ISO country code so it lines up with
     * the supplier/supermarket products it groups.
     */
    protected function countryOfOrigin(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => Countries::normalize($value));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * Public URL for the category picture, used as the product thumbnail. Prefers
     * the linked category's image and falls back to a name match (category name,
     * then the product's own name) so unlinked rows still resolve a picture.
     */
    public function displayImageUrl(): ?string
    {
        $path = filled($this->category?->image_path)
            ? $this->category->image_path
            : app(CategoryImages::class)->pathFor($this->category?->name ?? $this->name);

        return filled($path) ? Storage::disk('public')->url($path) : null;
    }

    public function packagingMethod(): BelongsTo
    {
        return $this->belongsTo(PackagingMethod::class);
    }

    /**
     * Build a human label from the packaging fields, e.g. "Plasă 4 kg", "250 g",
     * "kg" or "Caserolă". Returns null when no packaging is set.
     */
    public function composePackagingVariant(): ?string
    {
        $methodName = $this->packaging_method_id
            ? PackagingMethod::query()->whereKey($this->packaging_method_id)->value('name')
            : null;

        $size = $this->package_size !== null ? (float) $this->package_size : null;
        $unit = trim((string) $this->package_unit);

        $sizeUnit = $size !== null && $size > 0
            ? trim(rtrim(rtrim(number_format($size, 4, '.', ''), '0'), '.').' '.$unit)
            : $unit;

        $label = trim(($methodName ? $methodName.' ' : '').$sizeUnit);

        return $label !== '' ? $label : null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supplierProductMaps(): HasMany
    {
        return $this->hasMany(CanonicalSupplierProductMap::class);
    }

    public function supermarketProductMaps(): HasMany
    {
        return $this->hasMany(CanonicalSupermarketProductMap::class);
    }

    public function supplierProducts(): BelongsToMany
    {
        return $this->belongsToMany(SupplierProduct::class, 'canonical_supplier_product')
            ->withTimestamps();
    }

    public function supermarketProducts(): BelongsToMany
    {
        return $this->belongsToMany(SupermarketProduct::class, 'canonical_supermarket_product')
            ->withTimestamps();
    }
}
