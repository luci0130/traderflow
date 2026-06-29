<?php

namespace App\Modules\MarketComparison\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transporter extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'currency' => 'EUR',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'cost_per_km' => 'decimal:4',
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }
}
