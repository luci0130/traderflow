<?php

namespace App\Modules\Supermarkets\Models;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Products\Models\PackagingMethod;
use App\Support\CategoryImages;
use App\Support\Countries;
use Database\Factories\SupermarketProductFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SupermarketProduct extends Model
{
    use HasFactory;

    public const DEFAULT_VAT_RATE = 11.0;

    protected $guarded = [];

    protected static function newFactory(): SupermarketProductFactory
    {
        return SupermarketProductFactory::new();
    }

    protected function casts(): array
    {
        return [
            'package_size' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'is_bio' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (SupermarketProduct $product): void {
            if (filled($product->image_path)) {
                Storage::disk('public')->delete($product->image_path);
            }
        });
    }

    /**
     * Normalize the origin onto the canonical ISO country code regardless of how
     * it was entered (form code, scraped Romanian name, etc.).
     */
    protected function origin(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => Countries::normalize($value));
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

    public function prices(): HasMany
    {
        return $this->hasMany(SupermarketPrice::class);
    }

    public function packagingMethod(): BelongsTo
    {
        return $this->belongsTo(PackagingMethod::class);
    }

    public function canonicalProducts(): BelongsToMany
    {
        return $this->belongsToMany(CanonicalProduct::class, 'canonical_supermarket_product')
            ->withTimestamps();
    }
}
