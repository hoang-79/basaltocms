<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Saas\Billing\BillingRun;
use Basalto\Core\Saas\Saas;
use Illuminate\Console\Command;

/** `basalto:saas-billing` (TB 12 Scheibe 2): Herabstufungen, QR-Rechnungen und Erinnerungen — stündlich im Zeitplan, idempotent. */
final class SaasBillingCommand extends Command
{
    protected $signature = 'basalto:saas-billing';

    protected $description = 'Abrechnung der SaaS: fällige Herabstufungen, QR-Rechnungen je Periode, Erinnerungen an offene Rechnungen (je einmal)';

    public function handle(BillingRun $run): int
    {
        if (! Saas::enabled()) {
            $this->components->info('SaaS-Modus ist aus (BASALTO_SAAS) — nichts zu tun.');

            return self::SUCCESS;
        }
        $done = $run->run();
        $this->components->info(sprintf('Abrechnung: %d Planwechsel, %d QR-Rechnung(en), %d Erinnerung(en).', $done['plans'], $done['invoices'], $done['reminders']));

        return self::SUCCESS;
    }
}
