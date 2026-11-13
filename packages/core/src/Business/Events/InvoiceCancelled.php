<?php

declare(strict_types=1);

namespace Basalto\Core\Business\Events;

use Basalto\Core\Business\InvoiceData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * CR-020: Eine Rechnung der Finanzbuchhaltung ist als Entwurf gelöscht oder storniert worden. Das Plugin, das sie
 * angestossen hat (`$invoice->source`), gibt seine Positionen wieder frei — im Projektmanagement die erfasste Zeit.
 */
final class InvoiceCancelled
{
    use Dispatchable;

    public function __construct(public readonly int $siteId, public readonly InvoiceData $invoice) {}
}
