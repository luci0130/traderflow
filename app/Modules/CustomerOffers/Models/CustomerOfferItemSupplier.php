<?php

namespace App\Modules\CustomerOffers\Models;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The buy side of a customer offer line: one supplier to contact at a given
 * priority (1 = contact first). Carries the offered price snapshot plus the
 * purchasing agent's sourcing entries (transport cost, secured quantity, status).
 *
 * Scoped through its offer line (already tenant-bound), so it has no tenant_id.
 */
class CustomerOfferItemSupplier extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['transport_cost' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'include_in_order' => 'boolean',
            'unit_price' => 'decimal:4',
            'landed_cost' => 'decimal:4',
            'transport_cost' => 'decimal:4',
            'quantity_available' => 'decimal:4',
            'secured_quantity' => 'decimal:4',
        ];
    }

    protected function totalCost(): Attribute
    {
        return Attribute::get(function (): ?float {
            $price = $this->unit_price ?? $this->landed_cost;

            if ($price === null || $this->secured_quantity === null) {
                return null;
            }

            return round((float) $price * (float) $this->secured_quantity + (float) $this->transport_cost, 4);
        });
    }

    protected function effectiveUnitCost(): Attribute
    {
        return Attribute::get(function (): ?float {
            if ((float) ($this->secured_quantity ?? 0) <= 0 || $this->total_cost === null) {
                return null;
            }

            return $this->total_cost / (float) $this->secured_quantity;
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerOfferItem::class, 'customer_offer_item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
