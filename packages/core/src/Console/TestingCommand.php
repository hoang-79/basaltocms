<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Console\Concerns\PreparesVendorSite;
use Basalto\Core\Content\Pages;
use Illuminate\Console\Command;

/**
 * `basalto:testing` (TB 64, CR-012): die Testumgebung auf cyon. Wie `basalto:local`, aber für einen Host im Netz
 * statt Herd: Site des Herstellers anlegen, den Host (testing.basaltocms.com) zur primären Domain machen, Inhalte
 * einspielen, Doku auffrischen und die Adressen der Produktion in den Inhalten auf den Host umschreiben — die Demo
 * bleibt die öffentliche. Läuft mehrfach gefahrlos; in Produktion verweigert er.
 */
final class TestingCommand extends Command
{
    use PreparesVendorSite;

    protected $signature = 'basalto:testing
        {host : Host der Testumgebung, z. B. testing.basaltocms.com}
        {--no-publish : Inhalte nicht einspielen}';

    protected $description = 'Testumgebung einrichten: Hersteller-Site auf dem Host, Adressen der Produktion in den Inhalten umschreiben';

    public function handle(PageCache $pageCache, Pages $pages): int
    {
        if (app()->isProduction()) {
            $this->error('basalto:testing richtet nur eine Testumgebung ein — nicht in Produktion.');

            return self::FAILURE;
        }
        $host = strtolower(trim((string) $this->argument('host')));
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host) !== 1) {
            $this->error(sprintf('«%s» ist kein Host (etwa testing.basaltocms.com).', $host));

            return self::FAILURE;
        }

        $site = $this->vendorSite();
        if (! $this->makePrimaryHost($site, $host)) {
            return self::FAILURE;
        }
        $this->fillContent($site, ! $this->option('no-publish'));

        $changed = $this->localizeLinks($site, self::linkMap($host));
        $this->components->twoColumnDetail('Adressen', $changed === 0 ? 'nichts zu ändern' : sprintf('%d Fassungen auf %s', $changed, $host));
        $pages->forget();
        $pageCache->invalidate($site->id);

        $this->components->info(sprintf('Fertig: https://%s — Backend unter https://%s/%s', $host, $host, config('basalto.backend.path', 'admin')));

        return self::SUCCESS;
    }

    /**
     * Adressen der Produktion → Testumgebung. Die SaaS-Instanz «start» ist dieselbe Installation; Demo und Extranet
     * bleiben die öffentlichen.
     *
     * @return array<string, string>
     */
    public static function linkMap(string $host): array
    {
        return ['https://start.basaltocms.com' => 'https://'.$host, 'https://basaltocms.com' => 'https://'.$host];
    }
}
