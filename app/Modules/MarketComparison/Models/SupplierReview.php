<?php

namespace App\Modules\MarketComparison\Models;

use App\Models\User;
use App\Modules\Producers\Models\ProducerOrder;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReview extends Model
{
    public const MIN_RATING = 1;

    public const MAX_RATING = 5;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withoutGlobalScope('active_tenant');
    }

    public function producerOrder(): BelongsTo
    {
        return $this->belongsTo(ProducerOrder::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
