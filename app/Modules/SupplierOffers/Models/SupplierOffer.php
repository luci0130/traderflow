<?php

namespace App\Modules\SupplierOffers\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Documents\Models\Document;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SupplierOffer extends Model
{
    use \App\Modules\NumberSequences\Concerns\HasNumberSequence, BelongsToTenant, HasFactory;

    protected $guarded = [];

    public function numberSequenceKey(): string
    {
        return 'supplier_offer';
    }

    public function numberSequenceColumn(): string
    {
        return 'offer_number';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'valid_until' => 'date',
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
        return $this->hasMany(SupplierOfferItem::class);
    }

    public function supplierOrder(): HasOne
    {
        return $this->hasOne(SupplierOrder::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
