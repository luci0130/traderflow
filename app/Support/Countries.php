<?php

namespace App\Support;

class Countries
{
    /**
     * ISO-3166 alpha-2 code → display label. Curated subset covering the
     * common producer/buyer countries; extend as needed.
     *
     * @var array<string, string>
     */
    public const LIST = [
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'BG' => 'Bulgaria',
        'HR' => 'Croatia',
        'CY' => 'Cyprus',
        'CZ' => 'Czech Republic',
        'DK' => 'Denmark',
        'EE' => 'Estonia',
        'FI' => 'Finland',
        'FR' => 'France',
        'DE' => 'Germany',
        'GR' => 'Greece',
        'HU' => 'Hungary',
        'IE' => 'Ireland',
        'IT' => 'Italy',
        'LV' => 'Latvia',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'MT' => 'Malta',
        'NL' => 'Netherlands',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'ES' => 'Spain',
        'SE' => 'Sweden',
        'GB' => 'United Kingdom',
        'NO' => 'Norway',
        'CH' => 'Switzerland',
        'IS' => 'Iceland',
        'UA' => 'Ukraine',
        'MD' => 'Moldova',
        'RS' => 'Serbia',
        'AL' => 'Albania',
        'MK' => 'North Macedonia',
        'BA' => 'Bosnia and Herzegovina',
        'ME' => 'Montenegro',
        'TR' => 'Turkey',
        'IL' => 'Israel',
        'EG' => 'Egypt',
        'MA' => 'Morocco',
        'TN' => 'Tunisia',
        'ZA' => 'South Africa',
        'US' => 'United States',
        'CA' => 'Canada',
        'MX' => 'Mexico',
        'DO' => 'Dominican Republic',
        'BR' => 'Brazil',
        'AR' => 'Argentina',
        'CL' => 'Chile',
        'PE' => 'Peru',
        'CO' => 'Colombia',
        'EC' => 'Ecuador',
        'CN' => 'China',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'IN' => 'India',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'TH' => 'Thailand',
        'VN' => 'Vietnam',
        'PH' => 'Philippines',
        'ID' => 'Indonesia',
        'MY' => 'Malaysia',
    ];

    /**
     * Localized and alternate spellings (lowercased) → ISO alpha-2 code, used to
     * coerce free-text origins (e.g. scraped/photo data in Romanian) onto the
     * same canonical codes the forms store. English labels in self::LIST are
     * resolved automatically and don't need to be repeated here.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'belgia' => 'BE',
        'croația' => 'HR', 'croatia' => 'HR',
        'cipru' => 'CY',
        'cehia' => 'CZ', 'republica cehă' => 'CZ', 'republica ceha' => 'CZ',
        'danemarca' => 'DK',
        'finlanda' => 'FI',
        'franța' => 'FR', 'franta' => 'FR',
        'germania' => 'DE',
        'grecia' => 'GR',
        'ungaria' => 'HU',
        'irlanda' => 'IE',
        'italia' => 'IT',
        'letonia' => 'LV',
        'lituania' => 'LT',
        'luxemburg' => 'LU',
        'olanda' => 'NL', 'țările de jos' => 'NL', 'tarile de jos' => 'NL', 'netherlands' => 'NL',
        'polonia' => 'PL',
        'portugalia' => 'PT',
        'românia' => 'RO', 'romania' => 'RO',
        'slovacia' => 'SK',
        'spania' => 'ES',
        'suedia' => 'SE',
        'marea britanie' => 'GB', 'regatul unit' => 'GB', 'anglia' => 'GB',
        'norvegia' => 'NO',
        'elveția' => 'CH', 'elvetia' => 'CH',
        'islanda' => 'IS',
        'ucraina' => 'UA',
        'republica moldova' => 'MD', 'moldova' => 'MD',
        'serbia' => 'RS',
        'macedonia de nord' => 'MK', 'macedonia' => 'MK',
        'bosnia și herțegovina' => 'BA', 'bosnia si hertegovina' => 'BA', 'bosnia' => 'BA',
        'muntenegru' => 'ME',
        'turcia' => 'TR',
        'israel' => 'IL',
        'egipt' => 'EG',
        'maroc' => 'MA',
        'tunisia' => 'TN',
        'africa de sud' => 'ZA',
        'statele unite' => 'US', 'statele unite ale americii' => 'US', 'sua' => 'US',
        'canada' => 'CA',
        'mexic' => 'MX',
        'republica dominicană' => 'DO', 'republica dominicana' => 'DO',
        'brazilia' => 'BR',
        'argentina' => 'AR',
        'chile' => 'CL',
        'peru' => 'PE',
        'columbia' => 'CO',
        'ecuador' => 'EC',
        'china' => 'CN',
        'japonia' => 'JP',
        'coreea de sud' => 'KR', 'coreea' => 'KR',
        'india' => 'IN',
        'australia' => 'AU',
        'noua zeelandă' => 'NZ', 'noua zeelanda' => 'NZ',
        'thailanda' => 'TH',
        'vietnam' => 'VN',
        'filipine' => 'PH',
        'indonezia' => 'ID',
        'malaezia' => 'MY', 'malaysia' => 'MY',
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::LIST;
    }

    public static function label(?string $code): ?string
    {
        return $code === null ? null : (self::LIST[strtoupper($code)] ?? $code);
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::LIST[strtoupper($code)]);
    }

    /**
     * Coerce any country input — an ISO code, an English label, or a localized
     * name — onto the canonical ISO alpha-2 code so every table stores the same
     * representation. Unknown values are returned trimmed but otherwise intact
     * so nothing is silently lost; empty input becomes null.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $upper = strtoupper($value);

        if (isset(self::LIST[$upper])) {
            return $upper;
        }

        $lower = mb_strtolower($value);

        foreach (self::LIST as $code => $label) {
            if (mb_strtolower($label) === $lower) {
                return $code;
            }
        }

        return self::ALIASES[$lower] ?? $value;
    }
}
