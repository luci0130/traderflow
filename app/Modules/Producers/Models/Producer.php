<?php

namespace App\Modules\Producers\Models;

use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producer extends Supplier
{
    use HasFactory;

    protected $table = 'suppliers';

    protected $guarded = [];

    protected $attributes = [
        'tenant_id' => null,
        'management_mode' => Supplier::MANAGEMENT_MODE_SELF,
        'is_producer' => true,
        'status' => 'active',
        'default_currency' => 'EUR',
        'invoice_starting_number' => 1,
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('producer', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('is_producer'), true);
        });

        static::creating(function (Producer $producer): void {
            $producer->tenant_id = null;
            $producer->management_mode = Supplier::MANAGEMENT_MODE_SELF;
            $producer->is_producer = true;
        });
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'producer_id');
    }
}
