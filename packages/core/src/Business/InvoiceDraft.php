<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

/**
 * Eine Rechnung, die ein anderes Plugin der Finanzbuchhaltung übergibt (CR-020) — etwa die erfasste Zeit eines
 * Projekts. Die Buchhaltung legt sie als **Entwurf** an: MWST-Satz, Nummer, QR-Referenz und Buchung kommen erst beim
 * Ausstellen dort dazu. `$source` und `$sourceKey` sagen, woher sie kommt — wird sie gelöscht oder storniert, meldet
 * die Buchhaltung das mit {@see Events\InvoiceCancelled}.
 */
final readonly class InvoiceDraft
{
    /**
     * @param  list<InvoiceLine>  $lines
     * @param  string  $source  Handle des Plugins, z. B. «projects»
     * @param  string  $sourceKey  Kennung beim Plugin, z. B. die ID des Projekts
     * @param  int|null  $partnerId  Geschäftspartner aus dem CRM, sonst null
     * @param  array<string, string>  $recipient  Anschrift ohne CRM: name, company, street, postal_code, city, country, email
     */
    public function __construct(
        public string $title,
        public array $lines,
        public string $source,
        public string $sourceKey,
        public ?int $partnerId = null,
        public array $recipient = [],
        public string $currency = 'CHF',
        public string $note = '',
    ) {}

    /** Summe der Positionen ohne MWST in Rappen. */
    public function net(): int
    {
        return array_sum(array_map(static fn (InvoiceLine $line): int => $line->net(), $this->lines));
    }
}
