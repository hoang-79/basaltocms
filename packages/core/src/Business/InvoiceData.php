<?php

declare(strict_types=1);

namespace Basalto\Core\Business;

/** Eine Rechnung der Finanzbuchhaltung, wie andere Plugins sie sehen (CR-020) — Stand, Nummer, Betrag, Adresse. */
final readonly class InvoiceData
{
    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    /**
     * @param  string|null  $number  erst beim Ausstellen vergeben
     * @param  string  $status  {@see DRAFT}, {@see OPEN}, {@see PAID} oder {@see CANCELLED}
     * @param  int  $total  Total inkl. MWST in Rappen
     * @param  int  $outstanding  noch offen in Rappen
     */
    public function __construct(
        public int $id,
        public ?string $number,
        public string $status,
        public int $total,
        public string $currency,
        public ?string $url = null,
        public ?int $partnerId = null,
        public string $source = '',
        public string $sourceKey = '',
        public int $outstanding = 0,
    ) {}
}
