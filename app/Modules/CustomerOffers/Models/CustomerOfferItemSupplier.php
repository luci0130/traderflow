<?php

namespace App\Modules\CustomerOffers\Models;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The buy side of a customer offer line: one supplier to contact at a given
 * priority (1 = contact first). Carries the offered price snapshot plus the
 * purchasing agent's sourcing entries (landed cost, secured quantity, status).
 *
 * Scoped through its offer line (already tenant-bound), so it has no tenant_id.
 */
class CustomerOfferItemSupplier extends Model
{
    use HasFactory;

    protected $guarded = [];

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
            'quantity_available' => 'decimal:4',
            'secured_quantity' => 'decimal:4',
        ];
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
