<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Hosting\Hostnames;
use Basalto\Core\Models\SiteDomain;
use Illuminate\Console\Command;

/**
 * CR-017 (TB 87): Unterdomains beim Hoster einrichten und Zertifikate erneuern — jede Minute eingeplant, sobald etwas
 * fällig ist ({@see Hostnames::due()}). `--anfordern` merkt einen bestehenden Host vor (etwa ein Schaufenster, das vor
 * CR-017 entstand), `--jetzt` übergeht die Wartezeit nach einem Fehler.
 */
final class HostnamesCommand extends Command
{
    protected $signature = 'basalto:hostnamen {--anfordern=* : Host zum Einrichten vormerken} {--jetzt : Wartezeit nach einem Fehler übergehen}';

    protected $description = 'Unterdomains beim Hoster einrichten und Zertifikate von Let\'s Encrypt holen oder erneuern';

    public function handle(Hostnames $hostnames): int
    {
        if (! Hostnames::enabled()) {
            $this->info('Kein Hosting-Panel eingestellt (BASALTO_HOSTING_PANEL) — nichts zu tun.');

            return self::SUCCESS;
        }
        foreach ((array) $this->option('anfordern') as $host) {
            $domain = SiteDomain::query()->withoutGlobalScopes()->where('host', strtolower((string) $host))->first();
            if ($domain === null || ! Hostnames::manages($domain->host)) {
                $this->error(sprintf('%s ist keine Adresse einer Site in der Zone %s.', $host, Hostnames::zone()));

                return self::FAILURE;
            }
            Hostnames::request($domain);
        }
        $failed = false;
        foreach ($hostnames->run((bool) $this->option('jetzt')) as $result) {
            $failed = $failed || ! $result['ok'];
            $result['ok'] ? $this->info($result['host'].': '.$result['message']) : $this->warn($result['host'].': '.$result['message']);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
