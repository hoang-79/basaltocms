<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

use Basalto\Core\Invoices\SwissQrBill;

/**
 * Ein Geschäftspartner (Firma oder Person), wie ihn das CRM anderen Plugins zeigt (CR-020): Kunde, Interessent oder
 * Lieferant mit Nummer, Anschrift, UID und den E-Mail-Adressen von Partner und Kontaktpersonen. Nur lesen — wer ihn
 * ändern will, öffnet das CRM ({@see PartnerDirectory::url()}).
 */
final readonly class PartnerData
{
    public const COMPANY = 'company';

    public const PERSON = 'person';

    /** Lebenszyklus (Customer Lifecycle): Lead, Interessent, Kunde, ehemaliger Kunde */
    public const STAGES = ['lead', 'prospect', 'customer', 'former'];

    /**
     * @param  string  $number  Kundennummer, z. B. «K-0042»
     * @param  string  $kind  `company` oder `person`
     * @param  string  $name  Firmenname bzw. Vor- und Nachname
     * @param  string  $stage  einer der {@see STAGES}
     * @param  string  $street  Strasse und Hausnummer
     * @param  string  $country  ISO 3166-1 alpha-2
     * @param  string  $vatNumber  UID bzw. MWST-Nummer (CHE-123.456.789 MWST)
     * @param  string  $contactName  Hauptkontakt einer Firma
     * @param  list<string>  $emails  alle Adressen (Partner und Kontaktpersonen), klein geschrieben
     */
    public function __construct(
        public int $id,
        public string $number,
        public string $kind,
        public string $name,
        public string $stage = 'customer',
        public bool $supplier = false,
        public string $email = '',
        public string $phone = '',
        public string $street = '',
        public string $postalCode = '',
        public string $city = '',
        public string $country = 'CH',
        public string $vatNumber = '',
        public string $language = '',
        public string $contactName = '',
        public array $emails = [],
        public bool $archived = false,
    ) {}

    /** «K-0042 · Muster AG» — für Auswahllisten. */
    public function label(): string
    {
        return $this->number !== '' ? $this->number.' · '.$this->name : $this->name;
    }

    /**
     * Die Anschrift in der Form der Rechnungen des Kerns (QR-Rechnung, {@see SwissQrBill::bill()}).
     *
     * @return array{name: string, company: string, street: string, postal_code: string, city: string, country: string, email: string}
     */
    public function debtor(): array
    {
        $company = $this->kind === self::COMPANY;

        return ['name' => $company ? $this->contactName : $this->name, 'company' => $company ? $this->name : '', 'street' => $this->street,
            'postal_code' => $this->postalCode, 'city' => $this->city, 'country' => $this->country !== '' ? $this->country : 'CH', 'email' => $this->email];
    }

    /** @return list<string> Anschrift Zeile für Zeile (ohne Land, wenn Schweiz) */
    public function addressLines(): array
    {
        $lines = [$this->name];
        if ($this->kind === self::COMPANY && $this->contactName !== '') {
            $lines[] = $this->contactName;
        }
        $lines[] = $this->street;
        $lines[] = trim($this->postalCode.' '.$this->city);
        if (! in_array($this->country, ['', 'CH'], true)) {
            $lines[] = $this->country;
        }

        return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    /** Gehört die Adresse zu diesem Partner (Partner oder Kontaktperson)? */
    public function hasEmail(string $email): bool
    {
        return trim($email) !== '' && in_array(mb_strtolower(trim($email)), $this->emails, true);
    }
}
