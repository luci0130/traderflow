<?php

namespace App\Support;

use App\Models\CurrencyExchangeRate;

class ExchangeRate
{
    /** @var array<string, float>|null */
    private ?array $rates = null;

    public function convert(float $amount, string $from, string $to): ?float
    {
        if ($from === $to) {
            return $amount;
        }

        if ($amount === 0.0 && in_array($from, config('currency.currencies'), true) && in_array($to, config('currency.currencies'), true)) {
            return 0.0;
        }

        $rate = $this->rate($from, $to);

        return $rate !== null ? $amount * $rate : null;
    }

    public function rate(string $from, string $to): ?float
    {
        if ($from === $to) {
            return 1.0;
        }

        $this->rates ??= CurrencyExchangeRate::query()
            ->where('rate_date', CurrencyExchangeRate::query()->selectRaw('max(rate_date)'))
            ->get(['base_currency', 'quote_currency', 'rate'])
            ->mapWithKeys(fn (CurrencyExchangeRate $rate): array => [
                $rate->base_currency.'/'.$rate->quote_currency => (float) $rate->rate,
            ])->all();

        return $this->rates[$from.'/'.$to] ?? null;
    }

    public function forget(): void
    {
        $this->rates = null;
    }
}
