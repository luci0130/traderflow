<?php

namespace App\Modules\SupplierOrders\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Documents\Models\Document;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SupplierOrder extends Model
{
    use \App\Modules\NumberSequences\Concerns\HasNumberSequence, BelongsToTenant, HasFactory;

    protected $guarded = [];

    public function numberSequenceKey(): string
    {
        return 'supplier_order';
    }

    public function numberSequenceColumn(): string
    {
        return 'order_number';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierOffer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class);
    }

    public function customerOffer(): BelongsTo
    {
        return $this->belongsTo(CustomerOffer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
