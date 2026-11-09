<?php

namespace App\Support;

class PriceFormatter
{
    public function __construct(private ExchangeRate $exchangeRate) {}

    public function format(float|int|string|null $amount, string $currency, ?string $targetCurrency = null, bool $showOriginal = true): string
    {
        if ($amount === null) {
            return '—';
        }

        $currency = strtoupper($currency);
        $targetCurrency ??= Currency::preferred();
        $converted = $this->exchangeRate->convert((float) $amount, $currency, $targetCurrency);

        if ($converted === null) {
            return number_format((float) $amount, 2).' '.$currency.' ('.__('Exchange rate unavailable').')';
        }

        $formatted = number_format($converted, 2).' '.$targetCurrency;

        if ($showOriginal && $currency !== $targetCurrency) {
            $formatted .= ' ('.number_format((float) $amount, 2).' '.($currency === 'EUR' ? '€' : $currency).')';
        }

        return $formatted;
    }
}
