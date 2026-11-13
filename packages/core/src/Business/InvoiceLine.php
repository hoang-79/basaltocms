<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

/** Eine Position einer Rechnung, die ein anderes Plugin der Buchhaltung übergibt (CR-020) — Preis ohne MWST. */
final readonly class InvoiceLine
{
    /**
     * @param  float  $quantity  Menge, z. B. 2.5 (Stunden)
     * @param  string  $unit  Einheit, z. B. «h», «Stk.», «Pauschal»
     * @param  int  $unitPrice  Preis je Einheit ohne MWST in Rappen
     */
    public function __construct(
        public string $description,
        public float $quantity,
        public string $unit,
        public int $unitPrice,
        public ?string $detail = null,
    ) {}

    /** Betrag ohne MWST in Rappen. */
    public function net(): int
    {
        return (int) round($this->quantity * $this->unitPrice);
    }
}
