<?php

declare(strict_types=1);

namespace Basalto\Core\Invoices;

/**
 * Wer die Rechnung stellt und wohin bezahlt wird (QR-Rechnung): Name, Adresse, Konto (IBAN oder QR-IBAN) und die
 * BESR-ID, falls die Bank eine vergibt. Der Lizenzverkauf liest sie aus den Einstellungen der Site, die SaaS aus der
 * Konfiguration (`basalto.saas.billing.creditor`).
 */
final readonly class Creditor
{
    public function __construct(
        public string $name,
        public string $street,
        public string $building,
        public string $postalCode,
        public string $city,
        public string $country,
        public string $iban,
        public string $besrId,
    ) {}

    /** @param  array<string, mixed>  $values  Schlüssel wie in den Einstellungen: creditor|name, street, building, postal_code, city, country, iban, besr_id */
    public static function fromArray(array $values): self
    {
        $text = static fn (string $key): string => trim(is_scalar($values[$key] ?? null) ? (string) $values[$key] : '');
        $name = $text('creditor') !== '' ? $text('creditor') : $text('name');

        return new self($name, $text('street'), $text('building'), $text('postal_code'), $text('city'),
            strtoupper($text('country') !== '' ? $text('country') : 'CH'), strtoupper(str_replace(' ', '', $text('iban'))), $text('besr_id'));
    }

    /** Genug für einen Zahlteil: Name, Konto, PLZ und Ort. */
    public function complete(): bool
    {
        return $this->name !== '' && $this->iban !== '' && $this->postalCode !== '' && $this->city !== '';
    }

    /** @return list<string> Name, Strasse mit Nummer, PLZ und Ort — ohne leere Zeilen */
    public function lines(): array
    {
        return array_values(array_filter([$this->name, trim($this->street.' '.$this->building), trim($this->postalCode.' '.$this->city)], static fn (string $line): bool => $line !== ''));
    }
}
