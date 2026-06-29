<?php

namespace App\Http\Middleware;

use App\Support\Geolocation;
use App\Support\LocaleOptions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'ro', 'fr', 'de', 'es'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        if (LocaleOptions::isSupported($locale)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    public static function isSupported(string $locale): bool
    {
        return LocaleOptions::isSupported($locale);
    }

    private function resolveLocale(Request $request): string
    {
        $sessionLocale = $request->session()->get('locale');

        if (is_string($sessionLocale) && LocaleOptions::isSupported($sessionLocale)) {
            return $sessionLocale;
        }

        return $this->resolveLocaleFromCountry($request)
            ?? (string) config('app.locale', 'en');
    }

    private function resolveLocaleFromCountry(Request $request): ?string
    {
        return LocaleOptions::countryToLocale($this->countryFromHeaders($request))
            ?? LocaleOptions::countryToLocale(Geolocation::guessCountryCode($request->ip()));
    }

    private function countryFromHeaders(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Country-Code', 'X-App-Country'] as $header) {
            $country = $request->headers->get($header);

            if (is_string($country) && strlen($country) === 2) {
                return strtoupper($country);
            }
        }

        return null;
    }
}
