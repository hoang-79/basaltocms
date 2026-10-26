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
    public const DIAL_MAIN = ['1' => 'US'];

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
        $all = self::all();
        $top = array_map(static fn (string $c): array => $all[$c], self::TOP);
        $rest = array_values(array_filter($all, static fn (array $c): bool => ! in_array($c['code'], self::TOP, true)));
        // Alphabetisch nach den Regeln der Sprache («États-Unis» unter E), ohne intl nach Bytes
        $collator = class_exists(\Collator::class) ? new \Collator(app()->getLocale()) : null;
        usort($rest, static fn (array $a, array $b): int => $collator !== null ? (int) $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));

        return [...$top, ...$rest];
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
