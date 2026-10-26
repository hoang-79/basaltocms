<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Saas\Offboarding\ExitRun;
use Basalto\Core\Saas\Saas;
use Illuminate\Console\Command;

/** `basalto:saas-exit` (TB 12 Scheibe 4): Sperre nach der Laufzeit, Mails vor der Löschung, offene Umzüge — stündlich, idempotent. */
final class SaasExitCommand extends Command
{
    protected $signature = 'basalto:saas-exit';

    protected $description = 'Gekündigte SaaS-Konten sperren, vor der Löschung erinnern (7 Tage, 1 Tag) und offene Umzüge anstossen';

    public function handle(ExitRun $run): int
    {
        if (! Saas::enabled()) {
            $this->components->info('SaaS-Modus ist aus (BASALTO_SAAS) — nichts zu tun.');

            return self::SUCCESS;
        }
        $counts = $run->run();
        $this->components->info(sprintf('Austritt: %d gesperrt, %d Erinnerung(en) vor der Löschung, %d Umzug/Umzüge angestossen.', $counts['locked'], $counts['reminders'], $counts['moves']));

        return self::SUCCESS;
    }
}
