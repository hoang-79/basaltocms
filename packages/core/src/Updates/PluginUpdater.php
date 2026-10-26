<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Marketplace\MarketplaceClient;
use RuntimeException;

/**
 * Ein Plugin aus dem Marktplatz aktualisieren (CR-007, U-08): signiertes Archiv laden (mit E-Mail und Token des
 * Kundenkontos), prüfen und `vendor/<hersteller>/<paket>` tauschen — mit derselben Sicherung und demselben Rückweg wie
 * der Kern. Die neue Version merkt sich die Installation selbst (Composer weiss davon nichts).
 */
final class PluginUpdater extends ArchiveUpdate
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    private string $name = '';

    public function __construct(Installation $installation, PostSteps $steps, UpdateBackup $backup, private readonly MarketplaceClient $marketplace)
    {
        parent::__construct($installation, $steps, $backup);
    }

    public function for(string $name): self
    {
        if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#', $name) !== 1 || ! array_key_exists($name, $this->installation->plugins())) {
            throw new RuntimeException((string) __('Dieses Plugin ist nicht installiert.'));
        }
        $this->name = $name;

        return $this;
    }

    protected function target(): string
    {
        return 'plugin:'.$this->name;
    }

    protected function title(): string
    {
        return (string) __('Plugin :name', ['name' => $this->name]);
    }

    protected function download(string $work, UpdateStatus $status): array
    {
        $entry = null;
        foreach ($this->marketplace->catalog(true)['entries'] as $candidate) {
            if ($candidate['kind'] === 'plugin' && $candidate['name'] === $this->name) {
                $entry = $candidate;
            }
        }
        $installed = $this->installation->plugins()[$this->name] ?? null;
        if ($entry === null || ! Versions::newer($entry['version'], $installed)) {
            throw new RuntimeException((string) __('Für :name gibt es keine neuere Version.', ['name' => $this->name]));
        }
        $credentials = $this->marketplace->credentials();
        if ($credentials === null) {
            throw new RuntimeException((string) __('Für Plugins braucht Basalto E-Mail und Token Ihres Kundenkontos auf :host (auth.json oder BASALTO_MARKETPLACE_EMAIL/TOKEN).', ['host' => $this->marketplace->host()]));
        }
        $path = $work.'/plugin.zip';
        $this->fetch($this->marketplace->baseUrl().'/composer/dist/'.$this->name.'/'.rawurlencode($entry['version']).'.zip', $path, self::MAX_BYTES, $entry['sha256'], null, $credentials);
        PluginArchive::verify($path, $this->name, $entry['version'], (string) config('basalto.marketplace.public_key'));
        $status->step((string) __(':name :version geladen, Signatur geprüft', ['name' => $this->name, 'version' => $entry['version']]));

        return [$path, $entry['version']];
    }

    protected function validateStage(string $stage, string $version): void
    {
        $composer = json_decode((string) @file_get_contents($stage.'/composer.json'), true);
        if (! is_array($composer) || ($composer['name'] ?? null) !== $this->name) {
            throw new RuntimeException((string) __('Das Archiv enthält nicht :name.', ['name' => $this->name]));
        }
        @unlink($stage.'/basalto-manifest.json');
        @unlink($stage.'/basalto-signature');
        // Alles unter einen Ordner, der als Ganzes getauscht wird
        $package = $stage.'/../paket';
        rename($stage, $package);
        @mkdir($stage, 0775, true);
        rename($package, $stage.'/paket');
    }

    protected function entries(string $stage): array
    {
        return [['paket', 'vendor/'.$this->name]];
    }

    protected function afterSuccess(string $version): void
    {
        Installation::rememberPlugin($this->name, $version);
    }
}
