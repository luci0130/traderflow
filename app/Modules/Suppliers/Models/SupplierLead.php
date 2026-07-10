<?php

namespace App\Modules\Suppliers\Models;

use App\Models\User;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Database\Factories\SupplierLeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierLead extends Model
{
    /** @use HasFactory<SupplierLeadFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'country',
        'website',
        'email',
        'phone',
        'notes',
        'created_by',
        'supermarket_product_id',
        'converted_supplier_id',
        'converted_at',
    ];

    protected static function newFactory(): SupplierLeadFactory
    {
        return SupplierLeadFactory::new();
    }

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supermarketProduct(): BelongsTo
    {
        return $this->belongsTo(SupermarketProduct::class);
    }

    public function convertedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'converted_supplier_id');
    }

    public function isConverted(): bool
    {
        return $this->converted_supplier_id !== null;
    }
}
