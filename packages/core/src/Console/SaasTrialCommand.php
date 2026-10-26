<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\TrialReminders;
use Illuminate\Console\Command;

/** `basalto:saas-trial` (TB 12 Scheibe 1): Erinnerungen der Testphase — stündlich im Zeitplan, idempotent. */
final class SaasTrialCommand extends Command
{
    protected $signature = 'basalto:saas-trial';

    protected $description = 'Erinnerungen der SaaS-Testphase senden (vor dem Ende und am Ende, je einmal)';

    public function handle(TrialReminders $reminders): int
    {
        if (! Saas::enabled()) {
            $this->components->info('SaaS-Modus ist aus (BASALTO_SAAS) — nichts zu tun.');

            return self::SUCCESS;
        }
        $sent = $reminders->run();
        $this->components->info(sprintf('Testphase: %d Erinnerung(en) vor dem Ende, %d am Ende.', $sent['ending'], $sent['ended']));

        return self::SUCCESS;
    }
}
