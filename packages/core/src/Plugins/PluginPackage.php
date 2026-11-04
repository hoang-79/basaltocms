<?php

declare(strict_types=1);

namespace Basalto\Core\Plugins;

use Basalto\Core\Updates\Versions;
use Composer\InstalledVersions;
use RuntimeException;
use Throwable;

/**
 * Ein Plugin-Paket, wie es entpackt vor der Installation liegt (CR-018): `composer.json` an der Wurzel mit Typ
 * `basalto-plugin`, Handle unter `extra.basalto.module`, Service-Providern unter `extra.laravel.providers` und
 * PSR-4-Pfaden. `read()` prüft die Form, `problems()` die Verträglichkeit mit dieser Installation: die Kern-Version aus
 * `require.basalto/core`, PHP, Erweiterungen — und jede andere Bibliothek muss hier schon vorhanden sein (E-2: Plugins
 * bringen keine Bibliotheken mit). Dieselben Regeln prüft der Marktplatz beim Einreichen (TB 100).
 */
final class PluginPackage
{
    public const NAME = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#';

    private const HANDLE = '/^[a-z][a-z0-9_-]{0,39}$/';

    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';

    /**
     * @param  array<string, list<string>>  $psr4  Präfix → Ordner relativ zum Paket
     * @param  list<string>  $files  relativ zum Paket
     * @param  list<string>  $providers
     * @param  array<string, string>  $require
     */
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $module,
        public readonly string $label,
        public readonly array $psr4,
        public readonly array $files,
        public readonly array $providers,
        public readonly array $require,
    ) {}

    /** Paket aus einem Ordner lesen und seine Form prüfen; `$name`/`$version` aus dem Katalog müssen passen. */
    public static function read(string $dir, string $name, string $version): self
    {
        $composer = json_decode((string) @file_get_contents($dir.'/composer.json'), true);
        if (! is_array($composer)) {
            throw new RuntimeException((string) __('Im Archiv fehlt eine gültige composer.json.'));
        }
        if (($composer['name'] ?? null) !== $name || preg_match(self::NAME, $name) !== 1) {
            throw new RuntimeException((string) __('Das Archiv enthält nicht :name.', ['name' => $name]));
        }
        if (($composer['type'] ?? null) !== 'basalto-plugin') {
            throw new RuntimeException((string) __(':name ist kein Plugin (Typ basalto-plugin).', ['name' => $name]));
        }
        if (is_string($composer['version'] ?? null) && Versions::normalize($composer['version']) !== Versions::normalize($version)) {
            throw new RuntimeException((string) __('Das Archiv gehört nicht zu :name :version.', ['name' => $name, 'version' => $version]));
        }
        $basalto = is_array($composer['extra']['basalto'] ?? null) ? $composer['extra']['basalto'] : [];
        $module = is_string($basalto['module'] ?? null) ? $basalto['module'] : '';
        if (preg_match(self::HANDLE, $module) !== 1) {
            throw new RuntimeException((string) __(':name nennt kein gültiges Handle (extra.basalto.module).', ['name' => $name]));
        }
        $label = is_string($basalto['name'] ?? null) && trim($basalto['name']) !== '' ? mb_substr(trim($basalto['name']), 0, 80) : $name;

        $psr4 = [];
        foreach (is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : [] as $prefix => $paths) {
            $paths = is_string($paths) ? [$paths] : (is_array($paths) ? $paths : []);
            if (! is_string($prefix) || $prefix === '' || ! str_ends_with($prefix, '\\') || $paths === []) {
                throw new RuntimeException((string) __(':name hat ungültige PSR-4-Angaben.', ['name' => $name]));
            }
            foreach ($paths as $path) {
                if (! is_string($path) || ! self::inside($path) || ! is_dir($dir.'/'.$path)) {
                    throw new RuntimeException((string) __(':name hat ungültige PSR-4-Angaben.', ['name' => $name]));
                }
                $psr4[$prefix][] = trim($path, '/');
            }
        }
        if ($psr4 === []) {
            throw new RuntimeException((string) __(':name nennt keine Klassen (autoload.psr-4).', ['name' => $name]));
        }
        if (($composer['autoload']['classmap'] ?? []) !== []) {
            throw new RuntimeException((string) __(':name nutzt autoload.classmap — der Plugin-Lader kennt nur psr-4 und files.', ['name' => $name]));
        }
        $files = [];
        foreach (is_array($composer['autoload']['files'] ?? null) ? $composer['autoload']['files'] : [] as $file) {
            if (! is_string($file) || ! self::inside($file) || ! is_file($dir.'/'.$file)) {
                throw new RuntimeException((string) __(':name nennt eine Datei, die fehlt (autoload.files).', ['name' => $name]));
            }
            $files[] = $file;
        }
        $providers = [];
        foreach (is_array($composer['extra']['laravel']['providers'] ?? null) ? $composer['extra']['laravel']['providers'] : [] as $provider) {
            if (! is_string($provider) || preg_match(self::CLASS_NAME, $provider) !== 1 || self::fileOf($provider, $psr4, $dir) === null) {
                throw new RuntimeException((string) __(':name nennt einen Service-Provider, den es im Paket nicht gibt.', ['name' => $name]));
            }
            $providers[] = $provider;
        }
        if ($providers === []) {
            throw new RuntimeException((string) __(':name nennt keinen Service-Provider (extra.laravel.providers).', ['name' => $name]));
        }
        $require = [];
        foreach (is_array($composer['require'] ?? null) ? $composer['require'] : [] as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $require[$package] = $constraint;
            }
        }

        return new self($name, Versions::normalize($version), $module, $label, $psr4, $files, $providers, $require);
    }

    /**
     * Was gegen die Installation in dieser Umgebung spricht — leer heisst: verträglich.
     *
     * @return list<string>
     */
    public function problems(string $coreVersion): array
    {
        $problems = [];
        foreach ($this->require as $package => $constraint) {
            if ($package === 'php') {
                if (! Versions::satisfies(PHP_VERSION, $constraint)) {
                    $problems[] = (string) __(':name verlangt PHP :constraint, hier läuft :version.', ['name' => $this->label, 'constraint' => $constraint, 'version' => PHP_VERSION]);
                }

                continue;
            }
            if (str_starts_with($package, 'ext-')) {
                if (! extension_loaded(substr($package, 4))) {
                    $problems[] = (string) __(':name braucht die PHP-Erweiterung «:extension».', ['name' => $this->label, 'extension' => substr($package, 4)]);
                }

                continue;
            }
            if ($package === 'basalto/core') {
                if (! Versions::satisfies($coreVersion, $constraint)) {
                    $problems[] = (string) __(':name verlangt Basalto :constraint, installiert ist :version.', ['name' => $this->label, 'constraint' => $constraint, 'version' => $coreVersion]);
                }

                continue;
            }
            $installed = self::shippedVersion($package, $coreVersion);
            if ($installed === null) {
                $problems[] = (string) __(':name braucht die Bibliothek :package — Plugins nutzen nur, was Basalto mitliefert.', ['name' => $this->label, 'package' => $package]);
            } elseif ($constraint !== '*' && ! Versions::satisfies($installed, $constraint)) {
                $problems[] = (string) __(':name verlangt :package :constraint, vorhanden ist :version.', ['name' => $this->label, 'package' => $package, 'constraint' => $constraint, 'version' => $installed]);
            }
        }

        return $problems;
    }

    /**
     * Der Eintrag für die Liste des Plugin-Laders.
     *
     * @return array{version: string, module: string, label: string, psr4: array<string, list<string>>, files: list<string>, providers: list<string>}
     */
    public function record(): array
    {
        return ['version' => $this->version, 'module' => $this->module, 'label' => $this->label, 'psr4' => $this->psr4, 'files' => $this->files, 'providers' => $this->providers];
    }

    /**
     * In welcher Version diese Installation eine Bibliothek mitliefert — null, wenn sie fehlt oder nur zur Entwicklung
     * dient (das Release hat keine Entwicklungspakete). Mitgelieferte Module und Lava kommen als Pfad-Pakete
     * (Version «dev-…») und haben die Version des Kerns.
     */
    public static function shippedVersion(string $package, string $coreVersion): ?string
    {
        try {
            if (! InstalledVersions::isInstalled($package, false)) {
                return null;
            }
            if (str_starts_with($package, 'basalto/')) {
                return $coreVersion;
            }

            return InstalledVersions::getPrettyVersion($package) ?? '';
        } catch (Throwable) {
            return null;
        }
    }

    /** Der Ordner mit der `composer.json` — die Wurzel oder ein einziger umhüllender Ordner (wie bei GitHub-Archiven). */
    public static function rootIn(string $dir): ?string
    {
        if (is_file($dir.'/composer.json')) {
            return $dir;
        }
        $inner = array_values(array_filter(scandir($dir) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));

        return count($inner) === 1 && is_file($dir.'/'.$inner[0].'/composer.json') ? $dir.'/'.$inner[0] : null;
    }

    /** Ein Pfad im Paket — nicht absolut, kein «..». */
    private static function inside(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '..') && ! str_contains($path, '\\');
    }

    /** @param  array<string, list<string>>  $psr4 */
    private static function fileOf(string $class, array $psr4, string $dir): ?string
    {
        foreach ($psr4 as $prefix => $paths) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            foreach ($paths as $path) {
                if (is_file($dir.'/'.$path.'/'.$relative)) {
                    return $path.'/'.$relative;
                }
            }
        }

        return null;
    }
}
