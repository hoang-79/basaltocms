<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts\Events;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;

/**
 * TB 13 Scheibe 2: Ein Konto hat sich im Frontend mit dem richtigen Code angemeldet. `purpose` ist der Zweck aus dem
 * Formular (z. B. «members»), sonst null — die Mitglieder legen damit beim ersten Mal die Mitgliedschaft an.
 */
final readonly class CustomerSignedIn
{
    public function __construct(public Customer $customer, public Site $site, public ?string $purpose) {}
}
