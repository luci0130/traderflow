<?php

namespace App\Support;

class Currency
{
    /** @return list<string> */
    public static function supported(): array
    {
        return ['EUR', 'RON'];
    }

    public static function preferred(): string
    {
        $currency = auth()->user()?->preferred_currency;

        return in_array($currency, static::supported(), true)
            ? $currency
            : config('currency.default', 'RON');
    }
}
