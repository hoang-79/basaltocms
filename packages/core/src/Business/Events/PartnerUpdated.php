<?php

declare(strict_types=1);

namespace Basalto\Core\Business\Events;

use Basalto\Core\Business\PartnerData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * CR-020: Ein Geschäftspartner im CRM hat sich geändert — Name, Anschrift, Kontaktpersonen, Stand oder er ist aus dem
 * Archiv zurück. Plugins mit einer Kopie (Name auf Rechnungen, Projekten, Karten) gleichen sie hier ab.
 */
final class PartnerUpdated
{
    use Dispatchable;

    public function __construct(public readonly int $siteId, public readonly PartnerData $partner) {}
}
