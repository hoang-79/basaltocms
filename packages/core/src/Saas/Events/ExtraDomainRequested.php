<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Events;

use Basalto\Core\Models\Account;
use Basalto\Core\Models\Site;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * TB 12 Scheibe 3 → Scheibe 2: Ein Konto hat über dem Domain-Kontingent eine eigene Domain hinzugefügt und dafür eine
 * Zusatzdomain (CHF `basalto.saas.extra_domain_price` je Monat) bestellt. Die Bestellung steht zugleich in
 * `accounts.billing.extra_domain_orders`; das Abo bucht sie, zählt `billing.extra_domains` hoch und nimmt sie dort heraus.
 */
final class ExtraDomainRequested
{
    use Dispatchable;

    public function __construct(public readonly Account $account, public readonly Site $site, public readonly string $host, public readonly ?int $userId) {}
}
