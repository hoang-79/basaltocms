<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Jobs\VerifySiteDomain;
use Basalto\Core\Models\SiteDomain;
use Basalto\Core\Saas\Domains;
use Illuminate\Console\Command;

/**
 * Stellt für alle angelegten, noch nicht verbundenen Domains eine Prüfung in die Warteschlange (alle zehn Minuten).
 * TB 12 Scheibe 3: räumt dazu die Umleitungen alter Aliasse nach ihren 30 Tagen weg — danach ist der Name frei.
 */
final class VerifyDomainsCommand extends Command
{
    protected $signature = 'basalto:domains-verify';

    protected $description = 'Prüft angelegte, noch nicht verbundene Kundendomains über die Cloud-CLI';

    public function handle(): int
    {
        $pending = SiteDomain::query()->whereNotNull('cloud_domain_id')->whereNull('verified_at')->get();
        foreach ($pending as $domain) {
            VerifySiteDomain::dispatch($domain->id);
        }
        $this->info(sprintf('%d Domain(s) zur Prüfung eingestellt.', $pending->count()));
        $released = Domains::releaseExpiredAliases();
        if ($released > 0) {
            $this->info(sprintf('%d alte(r) Alias(se) nach der Umleitungsfrist freigegeben.', $released));
        }

        return self::SUCCESS;
    }
}
