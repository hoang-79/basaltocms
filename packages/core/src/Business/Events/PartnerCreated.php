<?php

declare(strict_types=1);

namespace Basalto\Core\Business\Events;

use Basalto\Core\Business\PartnerData;
use Illuminate\Foundation\Events\Dispatchable;

/** CR-020: Im CRM ist ein Geschäftspartner angelegt worden (auch per Import). */
final class PartnerCreated
{
    use Dispatchable;

    public function __construct(public readonly int $siteId, public readonly PartnerData $partner) {}
}
