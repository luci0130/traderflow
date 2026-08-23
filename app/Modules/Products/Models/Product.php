<?php

namespace App\Modules\Products\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Turiac\FilamentImageGallery\Concerns\HasImageGalleries;

class Product extends Model
{
    use HasFactory;
    use HasImageGalleries;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            $paths = collect([$product->image_path])
                ->filter()
                ->unique()
                ->values()
                ->all();

            Storage::disk('public')->delete($paths);
        });
    }

    /**
     * Use the main gallery image, then the legacy image path, and finally
     * the category image. Eager-load the gallery and category in lists.
     */
    protected function displayImagePath(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (filled($mainImage = $this->imageForRole('main', 'products')?->path)) {
                return $mainImage;
            }

            return filled($this->image_path)
                ? $this->image_path
                : $this->category?->image_path;
        });
    }

    /**
     * Public URL for the display image (own or inherited from the category),
     * or null when neither exists.
     */
    public function displayImageUrl(): ?string
    {
        return filled($this->display_image_path)
            ? Storage::disk('public')->url($this->display_image_path)
            : null;
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
