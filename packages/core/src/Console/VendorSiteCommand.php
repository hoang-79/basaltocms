<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Console\Concerns\PreparesVendorSite;
use Basalto\Core\Content\Pages;
use Illuminate\Console\Command;

/**
 * `basalto:hersteller-site` (TB 65, CR-012): die Site des Herstellers auf ihrem Host in Produktion — anlegen, den Host
 * (basaltocms.com) zur primären Domain machen, Inhalte einspielen und die Doku auffrischen. Anders als `basalto:local`
 * und `basalto:testing` schreibt er keine Adressen um und läuft auch in Produktion; mehrfach gefahrlos.
 */
final class VendorSiteCommand extends Command
{
    use PreparesVendorSite;

    protected $signature = 'basalto:hersteller-site
        {host : Host der Hersteller-Site, z. B. basaltocms.com}
        {--no-publish : Inhalte nicht einspielen}';

    protected $description = 'Hersteller-Site auf ihrem Host einrichten (Produktion): Site, Domain, Inhalte, Doku';

    public function handle(PageCache $pageCache, Pages $pages): int
    {
        $host = strtolower(trim((string) $this->argument('host')));
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host) !== 1) {
            $this->error(sprintf('«%s» ist kein Host (etwa basaltocms.com).', $host));

            return self::FAILURE;
        }
        $site = $this->vendorSite();
        if (! $this->makePrimaryHost($site, $host)) {
            return self::FAILURE;
        }
        $this->fillContent($site, ! $this->option('no-publish'));
        $pages->forget();
        $pageCache->invalidate($site->id);
        $this->components->info(sprintf('Fertig: https://%s — Backend unter https://%s/%s', $host, $host, config('basalto.backend.path', 'admin')));

        return self::SUCCESS;
    }
}
