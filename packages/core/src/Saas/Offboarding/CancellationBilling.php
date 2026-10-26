<?php

declare(strict_types=1);

namespace Basalto\Core\Saas\Offboarding;

use Basalto\Core\Models\Account;
use Carbon\CarbonImmutable;

/**
 * Die Naht zwischen Kündigung (TB 12 Scheibe 4) und Abo (Scheibe 2): Kündigen heisst «Abo zum Ende der bezahlten
 * Periode beenden», Zurücknehmen heisst «weiterlaufen lassen». Vorgabe ist {@see AccountBillingCancellation} über
 * `Saas\Billing\AccountBilling` (Stripe über Cashier, QR-Rechnung); Tests binden eine Attrappe.
 */
interface CancellationBilling
{
    /** Ende der laufenden, bezahlten Periode ohne etwas zu ändern (Vorschau «Laufzeit bis») — null: keine bezahlte Periode. */
    public function periodEnd(Account $account): ?CarbonImmutable;

    /** Abo zum Ende der bezahlten Periode kündigen und dieses Ende nennen — null: keine bezahlte Periode, die Laufzeit endet sofort. */
    public function cancelAtPeriodEnd(Account $account): ?CarbonImmutable;

    /** Die Kündigung zurücknehmen, solange die Laufzeit läuft. */
    public function resume(Account $account): void;
}
