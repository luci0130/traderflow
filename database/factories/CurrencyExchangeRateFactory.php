<?php

namespace Database\Factories;

use App\Models\CurrencyExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CurrencyExchangeRate> */
class CurrencyExchangeRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'base_currency' => 'EUR',
            'quote_currency' => 'RON',
            'rate' => 5,
            'rate_date' => today(),
            'source' => 'BNR',
        ];
    }

    public function inverse(): static
    {
        return $this->state(fn (): array => [
            'base_currency' => 'RON',
            'quote_currency' => 'EUR',
            'rate' => 0.2,
        ]);
    }
}
