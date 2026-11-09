<?php

namespace App\Modules\Supermarkets\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Supermarkets\Services\SupermarketCompanyResolver;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Database\Factories\SupermarketPriceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SupermarketPrice extends Model
{
    use HasFactory;

    public const SOURCE_PHOTO = 'photo';

    public const SOURCE_SCRAPER = 'scraper';

    public const SOURCE_MANUAL = 'manual';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $price): void {
            $price->forceFill(app(SupermarketCompanyResolver::class)->resolve(
                $price->supplier_lead_id,
                $price->supplier_id,
            ));
        });

        static::saved(function (self $price): void {
            if ($price->supplier_lead_id !== null) {
                $price->supplierLead()->firstOrFail()->supermarketProducts()->syncWithoutDetaching([$price->supermarket_product_id]);
            } elseif ($price->supplier_id !== null) {
                $price->supplier()->firstOrFail()->supermarketProducts()->syncWithoutDetaching([$price->supermarket_product_id]);
            }
        });
    }

    public function supplierLead(): BelongsTo
    {
        return $this->belongsTo(SupplierLead::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    protected function company(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->supplier_id !== null
                ? 'supplier:'.$this->supplier_id
                : ($this->supplier_lead_id !== null ? 'lead:'.$this->supplier_lead_id : null),
            set: function (?string $value): array {
                if (blank($value)) {
                    return ['supplier_lead_id' => null, 'supplier_id' => null];
                }

                if (! preg_match('/^(lead|supplier):([1-9][0-9]*)$/', $value, $matches)) {
                    throw ValidationException::withMessages(['company' => __('Select a valid company.')]);
                }

                return app(SupermarketCompanyResolver::class)->resolve(
                    $matches[1] === 'lead' ? (int) $matches[2] : null,
                    $matches[1] === 'supplier' ? (int) $matches[2] : null,
                );
            },
        );
    }

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
