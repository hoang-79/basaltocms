<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Core;
use Composer\InstalledVersions;
use Throwable;

/**
 * Die Installation aus Sicht der Updates (CR-007, U-03, U-06): Version des Kerns, wie sie gepflegt wird, mitgelieferte
 * Module und Plugins aus dem Marktplatz.
 *
 * - **archiv**: aus einem Release-Archiv (Web-Installer) — `basalto-release.json` an der Wurzel oder die Pfad-Quellen
 *   `modules/*` des Archivs in `composer.json`, ohne Git. Nur sie aktualisiert sich aus dem Backend.
 * - **git**: ein Arbeitsstand mit `.git` (Entwicklung) — aktualisiert wird mit Git und Composer.
 * - **composer**: per `composer create-project` — aktualisiert wird mit Composer.
 */
final class Installation
{
    public const RELEASE_FILE = 'basalto-release.json';

    /** Eigene Aufzeichnung der Plugins, die das Backend aktualisiert hat (Composer weiss davon nichts). */
    public const PLUGINS_FILE = 'app/updates/plugins.json';

    public function __construct(private readonly string $root) {}

    public static function current(): self
    {
        return new self(base_path());
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return 'archiv'|'git'|'composer' */
    public function mode(): string
    {
        // `.git` ist ein Ordner — oder in einem Worktree bzw. Submodul eine Datei
        if (file_exists($this->root.'/.git')) {
            return 'git';
        }
        if (is_file($this->root.'/'.self::RELEASE_FILE)) {
            return 'archiv';
        }
        $composer = json_decode((string) @file_get_contents($this->root.'/composer.json'), true);
        foreach (is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [] as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path' && ($repository['url'] ?? null) === 'modules/*') {
                return 'archiv';
            }
        }

        return 'composer';
    }

    /** Die installierte Version: aus dem Release-Archiv, sonst die des Kerns im Code. */
    public function version(): string
    {
        $release = json_decode((string) @file_get_contents($this->root.'/'.self::RELEASE_FILE), true);
        $version = is_array($release) && is_string($release['version'] ?? null) ? trim($release['version']) : '';

        return $version !== '' ? $version : Core::VERSION;
    }

    /** Stammt die Version aus `basalto-release.json`? (Ältere Archive kennen ihre Version nicht genau.) */
    public function knowsVersion(): bool
    {
        return is_file($this->root.'/'.self::RELEASE_FILE);
    }

    /**
     * Was einem Einspielen aus dem Backend im Weg steht — leer heisst: es geht.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->mode() !== 'archiv') {
            $problems[] = $this->mode() === 'git'
                ? (string) __('Diese Installation ist ein Git-Arbeitsstand — aktualisiert wird mit Git und Composer.')
                : (string) __('Diese Installation wird mit Composer gepflegt — aktualisiert wird mit Composer.');
        }
        // CR-011 TB 62: Der Browser startet das Update als eigenen Prozess — ohne proc_open (cyon) nur per SSH
        if (! PostSteps::canStartProcesses()) {
            $problems[] = (string) __('Dieses Hosting erlaubt PHP keine eigenen Prozesse (proc_open ist aus). Spielen Sie das Update per SSH ein: :command', ['command' => 'php artisan basalto:update --kern']);
        }
        if (! class_exists('ZipArchive')) {
            $problems[] = (string) __('Die PHP-Erweiterung «zip» fehlt.');
        }
        foreach (['', 'vendor', 'packages', 'modules', 'public', 'storage'] as $dir) {
            $path = rtrim($this->root.'/'.$dir, '/');
            if (is_dir($path) && ! is_writable($path)) {
                $problems[] = (string) __('Kein Schreibrecht auf :path.', ['path' => $dir === '' ? '/' : $dir]);
            }
        }

        return $problems;
    }

    /**
     * Mitgelieferte Module: Teil des Kern-Releases, ihre Version ist die des Kerns.
     *
     * @return list<array{handle: string, label: string, enabled_by_default: bool}>
     */
    public function bundledModules(): array
    {
        $out = [];
        foreach (app(Backend::class)->modules() as $module) {
            if ($module['handle'] === 'core') {
                continue;
            }
            $out[] = ['handle' => (string) $module['handle'], 'label' => (string) __((string) $module['label']), 'enabled_by_default' => (bool) $module['default']];
        }

        return $out;
    }

    /**
     * Plugins aus dem Marktplatz (Composer-Typ `basalto-plugin`) mit installierter Version — was das Backend selbst
     * aktualisiert hat, steht in der eigenen Aufzeichnung.
     *
     * @return array<string, string> Name → Version
     */
    public function plugins(): array
    {
        $out = [];
        try {
            foreach (InstalledVersions::getInstalledPackagesByType('basalto-plugin') as $name) {
                $out[$name] = (string) (InstalledVersions::getPrettyVersion($name) ?? '');
            }
        } catch (Throwable) {
            // ohne Composer-Daten (selten): keine Plugins
        }
        $own = json_decode((string) @file_get_contents(storage_path(self::PLUGINS_FILE)), true);
        foreach (is_array($own) ? $own : [] as $name => $version) {
            if (is_string($name) && is_string($version) && array_key_exists($name, $out)) {
                $out[$name] = $version;
            }
        }
        ksort($out);

        return $out;
    }

    public static function rememberPlugin(string $name, string $version): void
    {
        $file = storage_path(self::PLUGINS_FILE);
        $own = json_decode((string) @file_get_contents($file), true);
        $own = is_array($own) ? $own : [];
        $own[$name] = $version;
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, (string) json_encode($own, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
