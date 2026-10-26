<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Console\Concerns\PreparesVendorSite;
use Basalto\Core\Content\Pages;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Models\Site;
use Basalto\Core\Saas\LocalAliasFolders;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Vendor;
use Database\Seeders\BasaltoSiteSeeder;
use Illuminate\Console\Command;

/**
 * `basalto:local` (CR-004, P-03): die lokale Landschaft des Herstellers unter Laravel Herd. Der Ordner der Installation
 * liegt in einem geparkten Verzeichnis, Herd liefert ihn als `<ordner>.<tld>` aus (z. B. basalto.test). Der Befehl
 *
 * - prüft, ob der Elternordner in Herd geparkt ist,
 * - legt die Site des Herstellers an (BasaltoSiteSeeder), falls sie fehlt,
 * - macht `<ordner>.<tld>` zu ihrer primären Domain,
 * - veröffentlicht sie, solange sie noch keine Inhalte hat,
 * - und schreibt die Adressen der Produktion in ihren Inhalten auf die lokalen um (Demo, SaaS-Registrierung, Extranet).
 *
 * Läuft mehrfach gefahrlos; in Produktion verweigert er.
 */
final class LocalCommand extends Command
{
    use PreparesVendorSite;

    protected $signature = 'basalto:local
        {--herd-config= : Pfad der Herd-Konfiguration (valet/config.json)}
        {--no-publish : Inhalte nicht einspielen}';

    protected $description = 'Lokale Landschaft unter Herd einrichten: Hersteller-Site auf <ordner>.test, lokale Adressen in den Inhalten';

    public function handle(PageCache $pageCache, Pages $pages): int
    {
        if (app()->isProduction()) {
            $this->error('basalto:local richtet nur eine lokale Entwicklung ein — nicht in Produktion.');

            return self::FAILURE;
        }
        $herd = $this->herdConfig();
        $tld = is_string($herd['tld'] ?? null) && $herd['tld'] !== '' ? $herd['tld'] : 'test';
        $folder = basename(base_path());
        $parent = dirname(base_path());
        $host = $folder.'.'.$tld;

        $parked = in_array($parent, array_map(static fn (mixed $p): string => rtrim((string) $p, '/'), (array) ($herd['paths'] ?? [])), true);
        $parked
            ? $this->components->twoColumnDetail('Herd', sprintf('<fg=green>%s ist geparkt</> → %s', $parent, $host))
            : $this->components->warn(sprintf('%s ist in Herd nicht geparkt — «herd park» im Elternordner, sonst antwortet %s nicht.', $parent, $host));

        $site = $this->vendorSite();
        if (! $this->makePrimaryHost($site, $host)) {
            return self::FAILURE;
        }
        $this->fillContent($site, ! $this->option('no-publish'));

        $changed = $this->localizeLinks($site, $this->linkMap($host, $tld, $this->secured('demo-basalto.'.$tld)));
        $this->components->twoColumnDetail('Lokale Adressen', $changed === 0 ? 'nichts zu ändern' : sprintf('%d Fassungen angepasst', $changed));

        $pages->forget();
        $pageCache->invalidate($site->id);

        // TB 19 (P-09): Hersteller-Extranet auf extranet-<ordner>.test — Herd braucht dafür einen Link auf diesen Ordner
        if (Extranet::enabled()) {
            $extranet = Extranet::host();
            $linked = in_array($extranet, $this->herdLinks(), true) || is_link($parent.'/'.str_replace('.'.$tld, '', $extranet));
            $linked
                ? $this->components->twoColumnDetail('Extranet', 'https://'.$extranet)
                : $this->components->warn(sprintf('Extranet: «herd link %s» im Ordner %s, dann ist https://%s erreichbar.', str_replace('.'.$tld, '', $extranet), base_path(), $extranet));
        }

        // TB 18 (P-08): SaaS lokal — Registrierungen legen <alias> als Verweis in den Elternordner
        if (LocalAliasFolders::enabled()) {
            $removed = LocalAliasFolders::prune();
            $this->components->twoColumnDetail('SaaS lokal', sprintf('Verweise in %s%s', LocalAliasFolders::path(), $removed === [] ? '' : ' · aufgeräumt: '.implode(', ', $removed)));
        } elseif (Saas::enabled()) {
            $this->newLine();
            $this->line('  SaaS lokal: damit eine Registrierung unter <alias>.test erreichbar ist, in der .env:');
            $this->line(sprintf('  <fg=gray>BASALTO_SAAS_LOCAL_PATH=%s</>', $parent));
            $this->line('  <fg=gray>BASALTO_ALIAS_DOMAIN='.$tld.'</>');
        }

        if (Vendor::host() !== $host) {
            $this->newLine();
            $this->line(sprintf('  Lizenz- und Marktplatz-Links zeigen noch auf %s. Für lokal in der .env:', Vendor::host()));
            $this->line(sprintf('  <fg=gray>BASALTO_VENDOR_URL=https://%s</>', $host));
        }
        $this->newLine();
        $this->components->info(sprintf('Fertig: https://%s — Backend unter https://%s/%s', $host, $host, config('basalto.backend.path', 'admin')));

        return self::SUCCESS;
    }

    /**
     * Adressen der Produktion → lokal. Spezifische Hosts zuerst, die Hauptdomain zuletzt; die SaaS-Instanz «start» ist
     * lokal dieselbe Installation.
     *
     * @return array<string, string>
     */
    public function linkMap(string $host, string $tld, bool $demoSecured = false): array
    {
        $demo = ($demoSecured ? 'https' : 'http').'://demo-basalto.'.$tld;

        return [
            // Herd liefert die Demo nur mit Zertifikat («herd secure demo-basalto») über HTTPS — sonst über HTTP
            'https://demo.basaltocms.com' => $demo,
            ($demoSecured ? 'http' : 'https').'://demo-basalto.'.$tld => $demo,
            'https://extranet.basaltocms.com' => 'https://extranet-basalto.'.$tld,
            'https://start.basaltocms.com' => 'https://'.$host,
            'https://basaltocms.com' => 'https://'.$host,
        ];
    }

    /** Ob Herd für den Host ein Zertifikat hat (dann HTTPS). */
    private function secured(string $host): bool
    {
        return is_file(dirname($this->herdConfigPath()).'/Certificates/'.$host.'.crt');
    }

    private function herdConfigPath(): string
    {
        return (string) ($this->option('herd-config') ?: ((string) getenv('HOME')).'/Library/Application Support/Herd/config/valet/config.json');
    }

    /**
     * Hosts der mit `herd link` verknüpften Ordner (Herd legt sie als Verweise unter `valet/Sites` ab).
     *
     * @return list<string>
     */
    private function herdLinks(): array
    {
        $dir = dirname($this->herdConfigPath()).'/Sites';
        $tld = (string) ($this->herdConfig()['tld'] ?? 'test');
        $names = is_dir($dir) ? array_values(array_diff((array) scandir($dir), ['.', '..'])) : [];

        return array_map(static fn (mixed $name): string => $name.'.'.$tld, $names);
    }

    /** @return array<string, mixed> */
    private function herdConfig(): array
    {
        $path = $this->herdConfigPath();
        if (! is_file($path)) {
            return [];
        }
        $config = json_decode((string) file_get_contents($path), true);

        return is_array($config) ? $config : [];
    }
}
