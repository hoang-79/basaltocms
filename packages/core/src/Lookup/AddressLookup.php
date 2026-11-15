<?php

declare(strict_types=1);

namespace Basalto\Core\Lookup;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Adressen beim Tippen (CR-021, E-2): Schweiz aus dem amtlichen Gebäudeadressverzeichnis über swisstopo (GeoAdmin),
 * Frankreich aus der Base Adresse Nationale, alle übrigen Länder aus OpenStreetMap über Photon (komoot; für geringe
 * Mengen, mit Kennung). Ergebnis je Vorschlag: Strasse mit Hausnummer, dazu beides getrennt, PLZ, Ort, Land.
 *
 * @phpstan-import-type Suggestion from Lookups
 */
final class AddressLookup
{
    private const LIMIT = 8;

    private const SWISSTOPO_URL = 'https://api3.geo.admin.ch/rest/services/api/SearchServer';

    private const BAN_URL = 'https://api-adresse.data.gouv.fr/search/';

    private const PHOTON_URL = 'https://photon.komoot.io/api/';

    /** @return list<Suggestion> */
    public function search(string $query, string $country): array
    {
        try {
            return match ($country) {
                'CH' => $this->swisstopo($query),
                'FR' => $this->france($query),
                default => $this->photon($query, $country),
            };
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<Suggestion> */
    private function swisstopo(string $query): array
    {
        $response = Http::timeout(4)->withUserAgent(Lookups::userAgent())->acceptJson()
            ->get(self::SWISSTOPO_URL, ['type' => 'locations', 'origins' => 'address', 'limit' => self::LIMIT, 'searchText' => $query, 'sr' => 2056]);
        $out = [];
        foreach ((array) ($response->successful() ? $response->json('results') : []) as $row) {
            // «Hohfurristrasse 24 <b>8408 Winterthur</b>» — Strasse mit Nummer, dann PLZ und Ort
            $label = trim(html_entity_decode(strip_tags((string) ($row['attrs']['label'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('/^(.+?)\s+(\d{4})\s+(.+)$/u', $label, $m) !== 1) {
                continue;
            }
            $out[] = self::suggestion(['street' => $m[1], ...Lookups::splitStreet($m[1]), 'postal_code' => $m[2], 'city' => $m[3], 'country' => 'CH']);
        }

        return $out;
    }

    /** @return list<Suggestion> */
    private function france(string $query): array
    {
        $response = Http::timeout(4)->withUserAgent(Lookups::userAgent())->acceptJson()
            ->get(self::BAN_URL, ['q' => $query, 'limit' => self::LIMIT, 'type' => 'housenumber', 'autocomplete' => 1]);
        $out = [];
        foreach ((array) ($response->successful() ? $response->json('features') : []) as $row) {
            $p = is_array($row['properties'] ?? null) ? $row['properties'] : [];
            if (($p['name'] ?? '') === '') {
                continue;
            }
            $parts = isset($p['street'], $p['housenumber']) ? ['street_name' => (string) $p['street'], 'house_number' => (string) $p['housenumber']] : Lookups::splitStreet((string) $p['name']);
            $out[] = self::suggestion(['street' => (string) $p['name'], ...$parts, 'postal_code' => (string) ($p['postcode'] ?? ''), 'city' => (string) ($p['city'] ?? ''), 'country' => 'FR']);
        }

        return $out;
    }

    /** @return list<Suggestion> */
    private function photon(string $query, string $country): array
    {
        $lang = in_array(app()->getLocale(), ['de', 'en', 'fr'], true) ? app()->getLocale() : 'default';
        $response = Http::timeout(4)->withUserAgent(Lookups::userAgent())->acceptJson()
            ->get(self::PHOTON_URL, ['q' => $query, 'limit' => 15, 'lang' => $lang, 'layer' => 'house']);
        $out = [];
        foreach ((array) ($response->successful() ? $response->json('features') : []) as $row) {
            $p = is_array($row['properties'] ?? null) ? $row['properties'] : [];
            // Photon kennt keinen Filter nach Land — die Treffer im gewählten Land
            if (strtoupper((string) ($p['countrycode'] ?? '')) !== $country || ($p['street'] ?? '') === '') {
                continue;
            }
            $out[] = self::suggestion(['street' => trim((string) $p['street'].' '.(string) ($p['housenumber'] ?? '')), 'street_name' => (string) $p['street'],
                'house_number' => (string) ($p['housenumber'] ?? ''), 'postal_code' => (string) ($p['postcode'] ?? ''),
                'city' => (string) ($p['city'] ?? $p['town'] ?? $p['village'] ?? ''), 'country' => $country]);
            if (count($out) >= self::LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array{street: string, street_name: string, house_number: string, postal_code: string, city: string, country: string}  $values
     * @return Suggestion
     */
    private static function suggestion(array $values): array
    {
        return ['label' => $values['street'], 'detail' => trim($values['postal_code'].' '.$values['city']), 'values' => Lookups::values($values)];
    }
}
