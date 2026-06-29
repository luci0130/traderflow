<?php

namespace App\Modules\Supermarkets\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use Database\Factories\SupermarketPriceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupermarketPrice extends Model
{
    use HasFactory;

    public const SOURCE_PHOTO = 'photo';

    public const SOURCE_SCRAPER = 'scraper';

    public const SOURCE_MANUAL = 'manual';

    protected $guarded = [];

    protected static function newFactory(): SupermarketPriceFactory
    {
        return SupermarketPriceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'promo_price' => 'decimal:4',
            'is_promo' => 'boolean',
            'observed_at' => 'date',
        ];
    }

    public function supermarket(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'supermarket_id')->withoutGlobalScope('active_tenant');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupermarketProduct::class, 'supermarket_product_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(SupermarketPricePhoto::class, 'supermarket_price_photo_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * The VAT rate (percentage) that applies to this price, taken from the
     * related product and falling back to the default rate.
     */
    public function vatRate(): float
    {
        return (float) ($this->product?->vat_rate ?? SupermarketProduct::DEFAULT_VAT_RATE);
    }

    /**
     * The recorded gross `price` with VAT removed.
     */
    protected function priceExclVat(): Attribute
    {
        return Attribute::get(fn (): ?float => $this->removeVat($this->price));
    }

    /**
     * The recorded gross `promo_price` with VAT removed.
     */
    protected function promoPriceExclVat(): Attribute
    {
        return Attribute::get(fn (): ?float => $this->removeVat($this->promo_price));
    }

    private function removeVat(int|float|string|null $grossPrice): ?float
    {
        if ($grossPrice === null) {
            return null;
        }

        return round((float) $grossPrice / (1 + ($this->vatRate() / 100)), 4);
    }
}
