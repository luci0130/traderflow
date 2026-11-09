<?php

namespace App\Models;

use Database\Factories\CurrencyExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurrencyExchangeRate extends Model
{
    /** @use HasFactory<CurrencyExchangeRateFactory> */
    use HasFactory;

    protected $fillable = ['base_currency', 'quote_currency', 'rate', 'rate_date', 'source'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'rate_date' => 'date',
        ];
    }
}
