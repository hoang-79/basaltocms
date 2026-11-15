<?php

declare(strict_types=1);

namespace Basalto\Core\Lookup;

use Basalto\Core\Core;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;

/**
 * Nachschlagedienste für Formulare der Verwaltungsseiten (CR-021): Firmen aus Registern, Adressen aus Gebäude- und
 * Kartendaten. Je Site abschaltbar (Verwaltung › Einstellungen › Nachschlagen); der Browser fragt nur diesen Server.
 *
 * @phpstan-type Suggestion array{label: string, detail: string, values: array<string, string>}
 */
final class Lookups
{
    public const COMPANY = 'company';

    public const ADDRESS = 'address';

    /** Art → Einstellung der Site */
    private const SETTINGS = [self::COMPANY => 'lookup.companies', self::ADDRESS => 'lookup.addresses'];

    /** Ob ein Dienst für die Site an ist (ab Werk an). */
    public static function enabled(string $kind, ?Site $site): bool
    {
        $key = self::SETTINGS[$kind] ?? null;

        return $key !== null && $site !== null && app(Settings::class)->get($key, true, $site) !== false;
    }

    /**
     * Adressen der eingeschalteten Dienste für ein Formular — ohne eingeschalteten Dienst null.
     *
     * @param  list<string>  $kinds
     * @return array<string, string>|null
     */
    public static function urls(array $kinds): ?array
    {
        $site = app(CurrentSite::class)->get();
        $out = [];
        foreach ($kinds as $kind) {
            if (self::enabled($kind, $site)) {
                $out[$kind] = route('basalto.lookup', ['kind' => $kind]);
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Vorschläge eines Dienstes im Land.
     *
     * @return list<Suggestion>
     */
    public static function search(string $kind, string $query, string $country): array
    {
        return match ($kind) {
            self::COMPANY => app(CompanyLookup::class)->search($query, $country),
            self::ADDRESS => app(AddressLookup::class)->search($query, $country),
            default => [],
        };
    }

    /** Kennung in jeder Anfrage an einen Dienst (Photon und GLEIF bitten darum). */
    public static function userAgent(): string
    {
        return 'Basalto/'.Core::VERSION.' (+https://basaltocms.com)';
    }

    /**
     * Strasse und Hausnummer getrennt — für Formulare mit zwei Feldern (Absender der QR-Rechnung): «Hohfurristrasse 24a»
     * → Hohfurristrasse | 24a, «12 bis rue de la Paix» → rue de la Paix | 12 bis; ohne erkennbare Nummer alles als Strasse.
     *
     * @return array{street_name: string, house_number: string}
     */
    public static function splitStreet(string $street): array
    {
        $street = trim($street);
        if (preg_match('/^(.+?)\s+(\d+\s?[\p{L}]?(?:\s?[-\/]\s?\d+\s?[\p{L}]?)?)$/u', $street, $m) === 1) {
            return ['street_name' => $m[1], 'house_number' => $m[2]];
        }
        if (preg_match('/^(\d+\s?[\p{L}]?(?:\s(?:bis|ter|quater))?),?\s+(\D.*)$/iu', $street, $m) === 1) {
            return ['street_name' => $m[2], 'house_number' => $m[1]];
        }

        return ['street_name' => $street, 'house_number' => ''];
    }

    /**
     * Die Werte eines Vorschlags ohne leere — ausser der Hausnummer neben einer Strasse: Sie leert das Feld, damit keine
     * Nummer der alten Strasse stehen bleibt.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public static function values(array $values): array
    {
        $keepNumber = ($values['street_name'] ?? '') !== '';

        return array_filter($values, static fn (string $value, string $key): bool => $value !== '' || ($key === 'house_number' && $keepNumber), ARRAY_FILTER_USE_BOTH);
    }

    /** «HOHFURRISTRASSE 24» → «Hohfurristrasse 24» — Register schreiben gern gross. */
    public static function tidy(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value !== '' && mb_strtoupper($value) === $value && preg_match('/\p{L}{2}/u', $value) === 1) {
            $value = mb_convert_case(mb_strtolower($value), MB_CASE_TITLE);
        }

        return $value;
    }
}
