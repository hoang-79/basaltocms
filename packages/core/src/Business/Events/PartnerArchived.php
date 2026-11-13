<?php

declare(strict_types=1);

namespace Basalto\Core\Business\Events;

use Basalto\Core\Business\PartnerData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * CR-020: Ein Geschäftspartner ist im CRM archiviert worden. Er erscheint in keiner Auswahl mehr; bestehende Rechnungen,
 * Projekte und Karten behalten ihn.
 */
final class PartnerArchived
{
    use Dispatchable;

    public function __construct(public readonly int $siteId, public readonly PartnerData $partner) {}
}
