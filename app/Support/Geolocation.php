<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Stevebauman\Location\Facades\Location;
use Throwable;

class Geolocation
{
    /**
     * Best-effort lookup of the visitor's ISO-2 country code from an IP.
     * Cached per IP for 24h. Returns null when lookup fails, IP is
     * loopback, or the resolved country is not in our curated list.
     */
    public static function guessCountryCode(?string $ip): ?string
    {
        if ($ip === null || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        return Cache::remember(
            'geoip:country:'.$ip,
            now()->addDay(),
            function () use ($ip): ?string {
                try {
                    $position = Location::get($ip);
                } catch (Throwable) {
                    return null;
                }

                if ($position === false || ! is_string($position->countryCode ?? null)) {
                    return null;
                }

                $code = strtoupper($position->countryCode);

                return Countries::isValid($code) ? $code : null;
            },
        );
    }
}
