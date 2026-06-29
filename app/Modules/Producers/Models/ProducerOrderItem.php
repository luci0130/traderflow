<?php

namespace App\Modules\Producers\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProducerOrderItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        $recompute = function (self $item): void {
            $item->line_total = (float) $item->quantity * (float) $item->unit_price;
        };

        static::creating($recompute);
        static::updating($recompute);

        $touchOrder = fn (self $item) => $item->producerOrder?->recalculateTotal();
        static::saved($touchOrder);
        static::deleted($touchOrder);
    }

    public function producerOrder(): BelongsTo
    {
        return $this->belongsTo(ProducerOrder::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
