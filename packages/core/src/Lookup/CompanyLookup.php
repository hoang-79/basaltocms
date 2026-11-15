<?php

declare(strict_types=1);

namespace Basalto\Core\Lookup;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Firmen aus Registern (CR-021, E-1): Schweiz aus dem UID-Register des BFS (öffentlicher Webdienst, SOAP), Frankreich
 * aus «Recherche d'entreprises» (api.gouv.fr), alle übrigen Länder aus GLEIF (nur Firmen mit LEI). Ohne Antwort in
 * vier Sekunden oder bei einem Fehler: keine Vorschläge — tippen von Hand geht immer.
 *
 * @phpstan-import-type Suggestion from Lookups
 */
final class CompanyLookup
{
    private const LIMIT = 8;

    private const UID_URL = 'https://www.uid-wse.admin.ch/V5.0/PublicServices.svc';

    private const FR_URL = 'https://recherche-entreprises.api.gouv.fr/search';

    private const GLEIF_URL = 'https://api.gleif.org/api/v1/lei-records';

    /** @return list<Suggestion> */
    public function search(string $query, string $country): array
    {
        try {
            return match ($country) {
                'CH' => $this->uidRegister($query),
                'FR' => $this->france($query),
                default => $this->gleif($query, $country),
            };
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<Suggestion> */
    private function uidRegister(string $query): array
    {
        $name = htmlspecialchars($query, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:uid="http://www.uid.admin.ch/xmlns/uid-wse" xmlns:u5="http://www.uid.admin.ch/xmlns/uid-wse/5">'
            .'<soapenv:Body><uid:Search><uid:searchParameters><u5:uidEntitySearchParameters><u5:organisationName>'.$name.'</u5:organisationName>'
            .'</u5:uidEntitySearchParameters></uid:searchParameters></uid:Search></soapenv:Body></soapenv:Envelope>';
        $response = Http::timeout(4)->withUserAgent(Lookups::userAgent())
            ->withHeaders(['SOAPAction' => 'http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/Search'])
            ->withBody($body, 'text/xml; charset=utf-8')->post(self::UID_URL);
        if (! $response->successful()) {
            return [];
        }
        // Ohne Namensräume lesen: die Antwort mischt eCH-0097/0098/0108 — gesucht sind nur wenige Felder
        $plain = (string) preg_replace(['/(<\/?)[A-Za-z0-9]+:/', '/\sxmlns(:[A-Za-z0-9]+)?="[^"]*"/', '/\s[A-Za-z0-9]+:[A-Za-z]+="[^"]*"/'], ['$1', '', ''], $response->body());
        $xml = @simplexml_load_string($plain);
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach ($xml->xpath('//uidEntitySearchResultItem') ?: [] as $item) {
            $org = $item->organisation->organisation;
            $id = (string) $org->organisationIdentification->uid->uidOrganisationId;
            $name = trim((string) $org->organisationIdentification->organisationName);
            if ($id === '' || $name === '') {
                continue;
            }
            $uid = 'CHE-'.substr($id, 0, 3).'.'.substr($id, 3, 3).'.'.substr($id, 6, 3);
            $address = $org->address;
            $street = trim((string) $address->street.' '.(string) $address->houseNumber);
            $vat = $item->organisation->vatRegisterInformation;
            $vatActive = $vat->count() > 0 && (string) $vat->vatStatus === '2' && trim((string) $vat->vatLiquidationDate) === '';
            $values = ['name' => $name, 'street' => $street, 'street_name' => trim((string) $address->street), 'house_number' => trim((string) $address->houseNumber),
                'postal_code' => (string) $address->swissZipCode, 'city' => (string) $address->town,
                'country' => (string) ($address->countryIdISO2 ?: 'CH'), 'uid' => $uid, 'vat_number' => $uid.($vatActive ? ' MWST' : '')];
            $out[] = self::suggestion($values, $uid);
            if (count($out) >= self::LIMIT) {
                break;
            }
        }

        return $out;
    }

    /** @return list<Suggestion> */
    private function france(string $query): array
    {
        $response = Http::timeout(4)->withUserAgent(Lookups::userAgent())->acceptJson()
            ->get(self::FR_URL, ['q' => $query, 'per_page' => self::LIMIT, 'etat_administratif' => 'A']);
        $out = [];
        foreach ((array) ($response->successful() ? $response->json('results') : []) as $row) {
            if (! is_array($row) || ! is_string($row['siren'] ?? null)) {
                continue;
            }
            $siege = is_array($row['siege'] ?? null) ? $row['siege'] : [];
            $street = Lookups::tidy(implode(' ', array_filter([(string) ($siege['numero_voie'] ?? ''), (string) ($siege['indice_repetition'] ?? ''), (string) ($siege['type_voie'] ?? ''), (string) ($siege['libelle_voie'] ?? '')])));
            $siren = $row['siren'];
            // Numéro de TVA intracommunautaire aus der SIREN: Schlüssel (12 + 3 × (SIREN mod 97)) mod 97
            $key = (12 + 3 * ((int) $siren % 97)) % 97;
            $values = ['name' => Lookups::tidy((string) ($row['nom_complet'] ?? $row['nom_raison_sociale'] ?? '')), 'street' => $street,
                'street_name' => Lookups::tidy(implode(' ', array_filter([(string) ($siege['type_voie'] ?? ''), (string) ($siege['libelle_voie'] ?? '')]))),
                'house_number' => trim(implode(' ', array_filter([(string) ($siege['numero_voie'] ?? ''), mb_strtolower((string) ($siege['indice_repetition'] ?? ''))]))),
                'postal_code' => (string) ($siege['code_postal'] ?? ''), 'city' => Lookups::tidy((string) ($siege['libelle_commune'] ?? '')),
                'country' => 'FR', 'uid' => $siren, 'vat_number' => sprintf('FR%02d%s', $key, $siren)];
            $out[] = self::suggestion($values, 'SIREN '.$siren);
        }

        return $out;
    }

    /** @return list<Suggestion> */
    private function gleif(string $query, string $country): array
    {
        $response = Http::timeout(4)->withUserAgent(Lookups::userAgent())->acceptJson()->get(self::GLEIF_URL, [
            'filter[fulltext]' => $query, 'filter[entity.legalAddress.country]' => $country, 'filter[entity.status]' => 'ACTIVE', 'page[size]' => self::LIMIT,
        ]);
        $out = [];
        foreach ((array) ($response->successful() ? $response->json('data') : []) as $row) {
            $entity = is_array($row) ? ($row['attributes']['entity'] ?? null) : null;
            if (! is_array($entity)) {
                continue;
            }
            $address = is_array($entity['legalAddress'] ?? null) ? $entity['legalAddress'] : [];
            $lines = array_values(array_filter((array) ($address['addressLines'] ?? []), 'is_string'));
            $lei = (string) ($row['attributes']['lei'] ?? '');
            $values = ['name' => (string) ($entity['legalName']['name'] ?? ''), 'street' => trim((string) ($lines[0] ?? '')), ...Lookups::splitStreet((string) ($lines[0] ?? '')),
                'postal_code' => (string) ($address['postalCode'] ?? ''), 'city' => (string) ($address['city'] ?? ''), 'country' => (string) ($address['country'] ?? $country), 'uid' => $lei];
            $out[] = self::suggestion($values, 'LEI '.$lei);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $values
     * @return Suggestion
     */
    private static function suggestion(array $values, string $id): array
    {
        $place = trim($values['postal_code'].' '.$values['city']);

        return ['label' => $values['name'], 'detail' => implode(' · ', array_filter([$values['street'], $place, $id])), 'values' => Lookups::values($values)];
    }
}
