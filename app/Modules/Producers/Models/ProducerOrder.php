<?php

namespace App\Modules\Producers\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use App\Modules\MarketComparison\Models\SupplierReview;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProducerOrder extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'confirmed' => 'Confirmed',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'total' => 'decimal:4',
        ];
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(Producer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProducerOrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function review(): HasOne
    {
        return $this->hasOne(SupplierReview::class);
    }

    public function recalculateTotal(): void
    {
        $this->total = (float) $this->items()->sum('line_total');
        $this->save();
    }
}
