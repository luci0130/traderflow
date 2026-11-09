<?php

namespace App\Support;

use App\Models\CurrencyExchangeRate;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RefreshCurrencyRates
{
    public function __construct(private ExchangeRate $exchangeRate) {}

    /** @return array{pairs: int, date: string} */
    public function refresh(): array
    {
        $response = Http::connectTimeout(3)->timeout(10)->retry(3, 250)
            ->get(config('currency.bnr_url'))->throw();
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($response->body(), options: LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $cubes = $xml === false ? [] : $xml->xpath('//*[local-name()="Cube"]');
        $originalCurrency = $xml === false ? [] : $xml->xpath('//*[local-name()="OrigCurrency"]');
        $cube = $cubes[0] ?? null;
        $date = (string) ($cube['date'] ?? '');
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ((string) ($originalCurrency[0] ?? '') !== 'RON' || $cube === null || $parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new RuntimeException('The BNR feed contains no valid rate date.');
        }

        $currencies = config('currency.currencies');
        $ronRates = ['RON' => 1.0];

        foreach ($cube->xpath('./*[local-name()="Rate"]') as $node) {
            $currency = (string) $node['currency'];

            if (! in_array($currency, $currencies, true)) {
                continue;
            }

            $multiplier = (float) ($node['multiplier'] ?? 1);
            $value = (string) $node;
            $rate = $multiplier > 0 && is_numeric($value) ? (float) $value / $multiplier : 0;

            if (! is_finite($rate) || $rate <= 0) {
                throw new RuntimeException("The BNR feed contains an invalid rate for {$currency}.");
            }

            $ronRates[$currency] = $rate;
        }

        if (array_diff($currencies, array_keys($ronRates)) !== []) {
            throw new RuntimeException('The BNR feed is missing platform currencies.');
        }

        $pairs = [];

        foreach ($currencies as $base) {
            foreach ($currencies as $quote) {
                if ($base === $quote) {
                    continue;
                }

                $pairs[] = [
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'rate' => number_format($ronRates[$base] / $ronRates[$quote], 10, '.', ''),
                    'rate_date' => $date,
                    'source' => 'BNR',
                ];
            }
        }

        CurrencyExchangeRate::upsert($pairs, ['base_currency', 'quote_currency', 'rate_date'], ['rate', 'source', 'updated_at']);
        $this->exchangeRate->forget();

        return ['pairs' => count($pairs), 'date' => $date];
    }
}
