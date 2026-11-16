<?php

declare(strict_types=1);

namespace Basalto\Core\Support;

/**
 * Länder für Anschrift und Telefon (Profil, K-03): Name, Landesvorwahl, Länge der nationalen Rufnummer, Gruppierung
 * und das Muster der Postleitzahl — eine Quelle für Server und Oberfläche (das Frontend bekommt die Regeln als Daten).
 *
 * @phpstan-type Country array{code: string, name: string, dial: string, dial_main: bool, min: int, max: int, groups: list<int>, keep_zero: bool, postal: string|null, postal_example: string|null, postal_numeric: bool, postal_max: int}
 */
final class Countries
{
    /** Länder, die zuerst stehen (DACH und Nachbarn). */
    public const TOP = ['CH', 'LI', 'DE', 'AT', 'FR', 'IT'];

    /** Teilen sich Länder eine Vorwahl, gilt ohne gewähltes Land dieses — «+1» ist die USA, nicht Kanada. */
    public const DIAL_MAIN = ['1' => 'US', '7' => 'RU', '39' => 'IT', '44' => 'GB', '47' => 'NO', '61' => 'AU', '64' => 'NZ', '212' => 'MA', '246' => 'IO',
        '262' => 'RE', '290' => 'SH', '358' => 'FI', '590' => 'GP', '599' => 'CW'];

    /**
     * CR-022: Landesvorwahlen (ITU-T E.164) aller übrigen Länder und Gebiete mit eigener Ländervorwahl — sie kommen mit
     * allgemeinen Regeln dazu (Länge nach E.164, Gruppen zu drei, PLZ ohne Muster); die Namen liefert ICU in der Sprache
     * der Oberfläche. Ohne Eintrag: Gebiete ohne eigene Rufnummern (Bouvetinsel, Heard, Clipperton …) und Teile eines
     * Lands mit dessen Vorwahl (Kanaren, Ceuta und Melilla, Sark).
     */
    private const WORLD_DIAL = [
        'AC' => '247', 'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1', 'AI' => '1', 'AL' => '355', 'AM' => '374', 'AO' => '244', 'AR' => '54',
        'AS' => '1', 'AU' => '61', 'AW' => '297', 'AX' => '358', 'AZ' => '994', 'BA' => '387', 'BB' => '1', 'BD' => '880', 'BF' => '226', 'BG' => '359',
        'BH' => '973', 'BI' => '257', 'BJ' => '229', 'BL' => '590', 'BM' => '1', 'BN' => '673', 'BO' => '591', 'BQ' => '599', 'BR' => '55', 'BS' => '1',
        'BT' => '975', 'BW' => '267', 'BY' => '375', 'BZ' => '501', 'CC' => '61', 'CD' => '243', 'CF' => '236', 'CG' => '242', 'CI' => '225', 'CK' => '682',
        'CL' => '56', 'CM' => '237', 'CN' => '86', 'CO' => '57', 'CR' => '506', 'CU' => '53', 'CV' => '238', 'CW' => '599', 'CX' => '61', 'CY' => '357',
        'DG' => '246', 'DJ' => '253', 'DM' => '1', 'DO' => '1', 'DZ' => '213', 'EC' => '593', 'EE' => '372', 'EG' => '20', 'EH' => '212', 'ER' => '291',
        'ET' => '251', 'FJ' => '679', 'FK' => '500', 'FM' => '691', 'FO' => '298', 'GA' => '241', 'GD' => '1', 'GE' => '995', 'GF' => '594', 'GG' => '44',
        'GH' => '233', 'GI' => '350', 'GL' => '299', 'GM' => '220', 'GN' => '224', 'GP' => '590', 'GQ' => '240', 'GT' => '502', 'GU' => '1', 'GW' => '245',
        'GY' => '592', 'HK' => '852', 'HN' => '504', 'HT' => '509', 'ID' => '62', 'IL' => '972', 'IM' => '44', 'IN' => '91', 'IO' => '246', 'IQ' => '964',
        'IR' => '98', 'IS' => '354', 'JE' => '44', 'JM' => '1', 'JO' => '962', 'JP' => '81', 'KE' => '254', 'KG' => '996', 'KH' => '855', 'KI' => '686',
        'KM' => '269', 'KN' => '1', 'KP' => '850', 'KR' => '82', 'KW' => '965', 'KY' => '1', 'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1',
        'LK' => '94', 'LR' => '231', 'LS' => '266', 'LT' => '370', 'LV' => '371', 'LY' => '218', 'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382',
        'MF' => '590', 'MG' => '261', 'MH' => '692', 'MK' => '389', 'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853', 'MP' => '1', 'MQ' => '596',
        'MR' => '222', 'MS' => '1', 'MT' => '356', 'MU' => '230', 'MV' => '960', 'MW' => '265', 'MX' => '52', 'MY' => '60', 'MZ' => '258', 'NA' => '264',
        'NC' => '687', 'NE' => '227', 'NF' => '672', 'NG' => '234', 'NI' => '505', 'NP' => '977', 'NR' => '674', 'NU' => '683', 'NZ' => '64', 'OM' => '968',
        'PA' => '507', 'PE' => '51', 'PF' => '689', 'PG' => '675', 'PH' => '63', 'PK' => '92', 'PM' => '508', 'PN' => '64', 'PR' => '1', 'PS' => '970',
        'PW' => '680', 'PY' => '595', 'QA' => '974', 'RE' => '262', 'RO' => '40', 'RS' => '381', 'RU' => '7', 'RW' => '250', 'SA' => '966', 'SB' => '677',
        'SC' => '248', 'SD' => '249', 'SG' => '65', 'SH' => '290', 'SJ' => '47', 'SL' => '232', 'SM' => '378', 'SN' => '221', 'SO' => '252', 'SR' => '597',
        'SS' => '211', 'ST' => '239', 'SV' => '503', 'SX' => '1', 'SY' => '963', 'SZ' => '268', 'TA' => '290', 'TC' => '1', 'TD' => '235', 'TG' => '228',
        'TH' => '66', 'TJ' => '992', 'TK' => '690', 'TL' => '670', 'TM' => '993', 'TN' => '216', 'TO' => '676', 'TT' => '1', 'TV' => '688', 'TW' => '886',
        'TZ' => '255', 'UA' => '380', 'UG' => '256', 'UY' => '598', 'UZ' => '998', 'VA' => '39', 'VC' => '1', 'VE' => '58', 'VG' => '1', 'VI' => '1',
        'VU' => '678', 'WF' => '681', 'WS' => '685', 'XK' => '383', 'YE' => '967', 'YT' => '262', 'ZA' => '27', 'ZM' => '260', 'ZW' => '263',
    ];

    /** @var array<string, array<string, Country>> je Sprache der Oberfläche */
    private static array $cache = [];

    /** @var array<string, list<Country>> je Sprache der Oberfläche */
    private static array $orderedCache = [];

    /** EU und EWR — dort gilt das Widerrufsrecht für Verbraucher der Richtlinie 2011/83/EU (CR-011). */
    public const EU_EEA = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK', 'IS', 'LI', 'NO'];

    public static function inEuOrEea(?string $code): bool
    {
        return in_array(strtoupper((string) $code), self::EU_EEA, true);
    }

    /** @return array<string, Country> */
    public static function all(): array
    {
        return self::$cache[app()->getLocale()] ??= self::build();
    }

    /** @return array<string, Country> */
    private static function build(): array
    {
        $rows = [
            // Code, Name, Vorwahl, min, max, Gruppen, führende 0 gehört zur Nummer, PLZ-Muster, Beispiel
            ['CH', 'Schweiz', '41', 9, 9, [2, 3, 2, 2], false, '\d{4}', '8001'],
            ['LI', 'Liechtenstein', '423', 7, 7, [3, 2, 2], false, '94(8[5-9]|9[0-8])', '9490'],
            ['DE', 'Deutschland', '49', 6, 13, [3, 4, 4], false, '\d{5}', '10115'],
            ['AT', 'Österreich', '43', 4, 13, [3, 4, 4], false, '\d{4}', '1010'],
            ['FR', 'Frankreich', '33', 9, 9, [1, 2, 2, 2, 2], false, '\d{5}', '75001'],
            ['IT', 'Italien', '39', 6, 11, [3, 3, 4], true, '\d{5}', '00184'],
            ['BE', 'Belgien', '32', 8, 9, [3, 2, 2, 2], false, '\d{4}', '1000'],
            ['NL', 'Niederlande', '31', 9, 9, [1, 4, 4], false, '\d{4} ?[A-Za-z]{2}', '1012 AB'],
            ['LU', 'Luxemburg', '352', 6, 11, [3, 3, 3], false, '\d{4}', '1009'],
            ['ES', 'Spanien', '34', 9, 9, [3, 3, 3], false, '\d{5}', '28001'],
            ['PT', 'Portugal', '351', 9, 9, [3, 3, 3], false, '\d{4}-\d{3}', '1000-001'],
            ['GB', 'Vereinigtes Königreich', '44', 10, 10, [4, 6], false, '[A-Za-z]{1,2}\d[A-Za-z\d]? ?\d[A-Za-z]{2}', 'SW1A 1AA'],
            ['IE', 'Irland', '353', 7, 9, [2, 3, 4], false, null, null],
            ['DK', 'Dänemark', '45', 8, 8, [2, 2, 2, 2], false, '\d{4}', '1050'],
            ['SE', 'Schweden', '46', 7, 9, [2, 3, 2, 2], false, '\d{3} ?\d{2}', '111 22'],
            ['NO', 'Norwegen', '47', 8, 8, [3, 2, 3], false, '\d{4}', '0150'],
            ['FI', 'Finnland', '358', 6, 10, [2, 3, 4], false, '\d{5}', '00100'],
            ['PL', 'Polen', '48', 9, 9, [3, 3, 3], false, '\d{2}-\d{3}', '00-001'],
            ['CZ', 'Tschechien', '420', 9, 9, [3, 3, 3], false, '\d{3} ?\d{2}', '110 00'],
            ['SK', 'Slowakei', '421', 9, 9, [3, 3, 3], false, '\d{3} ?\d{2}', '811 01'],
            ['HU', 'Ungarn', '36', 8, 9, [2, 3, 4], false, '\d{4}', '1011'],
            ['SI', 'Slowenien', '386', 8, 8, [2, 3, 3], false, '\d{4}', '1000'],
            ['HR', 'Kroatien', '385', 8, 9, [2, 3, 4], false, '\d{5}', '10000'],
            ['GR', 'Griechenland', '30', 10, 10, [3, 3, 4], false, '\d{3} ?\d{2}', '105 57'],
            ['TR', 'Türkei', '90', 10, 10, [3, 3, 2, 2], false, '\d{5}', '34000'],
            ['US', 'Vereinigte Staaten', '1', 10, 10, [3, 3, 4], false, '\d{5}(-\d{4})?', '10001'],
            ['CA', 'Kanada', '1', 10, 10, [3, 3, 4], false, '[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d', 'K1A 0B1'],
            ['VN', 'Vietnam', '84', 9, 10, [2, 4, 4], false, '\d{6}', '100000'],
        ];
        $out = [];
        foreach ($rows as [$code, $name, $dial, $min, $max, $groups, $keepZero, $postal, $example]) {
            // PLZ nur aus Ziffern, wo das Muster keine Buchstaben kennt (CH, DE, AT …); Länge aus dem Beispiel
            $numeric = $postal === null || preg_match('/[A-Za-z]/', str_replace(['\\d'], '', $postal)) !== 1;
            $out[$code] = ['code' => $code, 'name' => self::localName($code, $name), 'dial' => $dial, 'dial_main' => (self::DIAL_MAIN[$dial] ?? $code) === $code, 'min' => $min, 'max' => $max, 'groups' => $groups, 'keep_zero' => $keepZero, 'postal' => $postal, 'postal_example' => $example,
                'postal_numeric' => $postal !== null && $numeric, 'postal_max' => $example !== null ? strlen($example) : 10];
        }
        // CR-022: alle übrigen Länder — Länge nach E.164 (höchstens 15 Ziffern mit Vorwahl), Gruppen zu drei, PLZ frei
        foreach (self::WORLD_DIAL as $code => $dial) {
            if (isset($out[$code])) {
                continue;
            }
            $german = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, 'de') : $code;
            $out[$code] = ['code' => $code, 'name' => self::localName($code, $german !== '' ? $german : $code), 'dial' => $dial, 'dial_main' => (self::DIAL_MAIN[$dial] ?? $code) === $code,
                'min' => 4, 'max' => 15 - strlen($dial), 'groups' => [3, 3, 3, 3], 'keep_zero' => false, 'postal' => null, 'postal_example' => null, 'postal_numeric' => false, 'postal_max' => 10];
        }

        return $out;
    }

    /**
     * Der Name in der Sprache der Oberfläche (N-01): Deutsch aus der Tabelle, sonst aus den Länderdaten von ICU (intl);
     * ohne intl bleibt der deutsche Name.
     */
    private static function localName(string $code, string $german): string
    {
        $locale = app()->getLocale();
        if ($locale === 'de' || ! class_exists(\Locale::class)) {
            return $german;
        }
        // Ohne Treffer liefert intl einen leeren Text, das Polyfill den Code selbst — dann gilt der deutsche Name
        $name = \Locale::getDisplayRegion('-'.$code, $locale);

        return $name !== '' && $name !== $code ? $name : $german;
    }

    /** @return Country|null */
    public static function find(?string $code): ?array
    {
        return $code === null ? null : (self::all()[strtoupper($code)] ?? null);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * Die Länder in Anzeigereihenfolge: DACH und Nachbarn zuerst, dann alphabetisch.
     *
     * @return list<Country>
     */
    public static function ordered(): array
    {
        return self::$orderedCache[app()->getLocale()] ??= self::sorted();
    }

    /** @return list<Country> */
    private static function sorted(): array
    {
        $all = self::all();
        $top = array_map(static fn (string $c): array => $all[$c], self::TOP);
        $rest = array_values(array_filter($all, static fn (array $c): bool => ! in_array($c['code'], self::TOP, true)));
        // Alphabetisch nach den Regeln der Sprache («États-Unis» unter E), ohne intl nach Bytes
        $collator = class_exists(\Collator::class) ? new \Collator(app()->getLocale()) : null;
        usort($rest, static fn (array $a, array $b): int => $collator !== null ? (int) $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));

        return [...$top, ...$rest];
    }

    /**
     * Die Länder für Formulare im Browser (Profil, Verwaltungsseiten CR-021): Name, Vorwahl, Längen und Gruppen der
     * Rufnummer, Beispiel und Regeln der Postleitzahl — in der Reihenfolge von `ordered()`.
     *
     * @return list<array{code: string, name: string, dial: string, dial_main: bool, min: int, max: int, groups: list<int>, keep_zero: bool, postal_example: string|null, postal_numeric: bool, postal_max: int}>
     */
    public static function forForms(): array
    {
        return array_map(static fn (array $c): array => ['code' => $c['code'], 'name' => $c['name'], 'dial' => $c['dial'], 'dial_main' => $c['dial_main'], 'min' => $c['min'], 'max' => $c['max'],
            'groups' => $c['groups'], 'keep_zero' => $c['keep_zero'], 'postal_example' => $c['postal_example'], 'postal_numeric' => $c['postal_numeric'], 'postal_max' => $c['postal_max']], self::ordered());
    }

    /**
     * Eine eingegebene Rufnummer lesen: «079 123 45 67» mit Land CH, «+41 79 …», «0041 79 …» — Ergebnis E.164 und Land,
     * oder null, wenn es keine anrufbare Nummer ist (Buchstaben, falsche Länge, unbekannte Vorwahl).
     *
     * @return array{e164: string, country: string}|null
     */
    public static function parsePhone(string $input, string $country): ?array
    {
        $reading = self::readPhone($input, self::find($country));
        if ($reading === null) {
            return null;
        }
        ['country' => $c, 'national' => $national] = $reading;
        $length = strlen($national);
        if ($length < $c['min'] || $length > $c['max']) {
            return null;
        }

        return ['e164' => '+'.$c['dial'].$national, 'country' => $c['code']];
    }

    /** Eine gespeicherte Nummer (E.164) lesbar: «+41 79 123 45 67». */
    public static function formatPhone(?string $e164, ?string $country = null): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }
        $reading = self::readPhone($e164, self::find($country));
        if ($reading === null || ! $reading['international']) {
            return $e164;
        }
        $grouped = self::groupDigits($reading['national'], $reading['country']['groups']);

        return '+'.$reading['country']['dial'].($grouped === '' ? '' : ' '.$grouped);
    }

    /**
     * Nationale Ziffern in die Gruppen des Landes: «791234567» mit [2, 3, 2, 2] → «79 123 45 67». Kürzere füllen die
     * Gruppen, so weit sie reichen; was über die Gruppen hinausgeht, hängt an der letzten (wie `groupDigits` in `phone.ts`).
     *
     * @param  list<int>  $groups
     */
    public static function groupDigits(string $digits, array $groups): string
    {
        if ($groups === []) {
            return $digits;
        }
        $parts = [];
        $start = 0;
        $last = count($groups) - 1;
        foreach ($groups as $index => $size) {
            if ($start >= strlen($digits)) {
                break;
            }
            $part = $index === $last ? substr($digits, $start) : substr($digits, $start, $size);
            $parts[] = $part;
            $start += strlen($part);
        }

        return implode(' ', $parts);
    }

    /**
     * Eine Eingabe lesen, ohne die Länge zu prüfen — dieselben Regeln wie `parsePhone` in `phone.ts`: Trennzeichen
     * (Leerzeichen, «-», «.», «/», «(», «)») fallen weg, sonst nur Ziffern und ein «+» vorne. «+» oder «00» vorne heisst
     * international, das Land kommt aus der längsten passenden Vorwahl (bei geteilter Vorwahl das gewählte Land, sonst
     * das Hauptland aus `DIAL_MAIN`); sonst gilt das gewählte Land. Eine führende 0 fällt weg, auch nach
     * der Vorwahl («+41 (0)79 …»), ausser wo sie zur Nummer gehört (`keep_zero`).
     *
     * @param  Country|null  $chosen
     * @return array{country: Country, national: string, international: bool}|null
     */
    private static function readPhone(string $input, ?array $chosen): ?array
    {
        $text = preg_replace('/[\s\-.\/()]/u', '', $input);
        if ($text === null || preg_match('/^\+?[0-9]*$/', $text) !== 1) {
            return null;
        }
        $prefix = str_starts_with($text, '+') ? 1 : (str_starts_with($text, '00') ? 2 : 0);
        $digits = substr($text, $prefix);
        $country = $chosen;
        if ($prefix > 0) {
            $country = self::byDial($digits, $chosen);
            if ($country === null) {
                return null;
            }
            $digits = substr($digits, strlen($country['dial']));
        } elseif ($country === null || $digits === '') {
            return null;
        }
        if (! $country['keep_zero'] && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return ['country' => $country, 'national' => $digits, 'international' => $prefix > 0];
    }

    /**
     * Das Land zu den Ziffern nach «+»: die längste passende Vorwahl. Teilen sich Länder eine Vorwahl («1»), gewinnt das
     * gewählte, sonst das Hauptland aus `DIAL_MAIN` (USA), sonst das erste in `ordered()`.
     *
     * @param  Country|null  $chosen
     * @return Country|null
     */
    private static function byDial(string $digits, ?array $chosen): ?array
    {
        $best = null;
        foreach (self::ordered() as $c) {
            if ($c['dial'] !== '' && str_starts_with($digits, $c['dial']) && ($best === null || strlen($c['dial']) > strlen($best['dial']))) {
                $best = $c;
            }
        }

        if ($best === null) {
            return null;
        }
        if ($chosen !== null && $chosen['dial'] === $best['dial']) {
            return $chosen;
        }
        $main = self::DIAL_MAIN[$best['dial']] ?? null;

        return $main !== null ? (self::find($main) ?? $best) : $best;
    }

    /** Passt die Postleitzahl zum Land? Ohne bekanntes Muster: höchstens zehn Zeichen aus Ziffern, Buchstaben, Leerzeichen, Bindestrich. */
    public static function validPostalCode(string $postal, string $country): bool
    {
        $c = self::find($country);
        $postal = trim($postal);
        if ($c === null || $c['postal'] === null) {
            return preg_match('/^[A-Za-z0-9 \-]{2,10}$/', $postal) === 1;
        }

        return preg_match('/^(?:'.$c['postal'].')$/', $postal) === 1;
    }
}
