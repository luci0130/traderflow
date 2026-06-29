<?php

namespace App\Support;

use Filament\Navigation\MenuItem;
use Filament\Support\Icons\Heroicon;

class LocaleOptions
{
    /**
     * @return array<string, array{name: string, native_name: string}>
     */
    public static function supported(): array
    {
        return config('localization.supported_locales', [
            'en' => [
                'name' => 'English',
                'native_name' => 'English',
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function supportedCodes(): array
    {
        return array_keys(static::supported());
    }

    public static function isSupported(string $locale): bool
    {
        return array_key_exists(strtolower($locale), static::supported());
    }

    public static function countryToLocale(?string $countryCode): ?string
    {
        if (blank($countryCode)) {
            return null;
        }

        $locale = config('localization.country_locale_map.'.strtoupper($countryCode));

        return is_string($locale) && static::isSupported($locale) ? $locale : null;
    }

    public static function nativeName(string $locale): string
    {
        return static::supported()[$locale]['native_name'] ?? strtoupper($locale);
    }

    /**
     * @return array<string, MenuItem>
     */
    public static function filamentUserMenuItems(): array
    {
        return collect(static::supported())
            ->mapWithKeys(fn (array $language, string $locale): array => [
                'language_'.$locale => MenuItem::make()
                    ->label(fn (): string => (app()->getLocale() === $locale ? '* ' : '').$language['native_name'])
                    ->icon(Heroicon::OutlinedLanguage)
                    ->url(fn (): string => route('locale.switch', ['locale' => $locale])),
            ])
            ->all();
    }
}
