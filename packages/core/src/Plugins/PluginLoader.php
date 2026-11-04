<?php

declare(strict_types=1);

namespace Basalto\Core\Plugins;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Plugins, die das Backend installiert hat (CR-018, E-1) — ohne Composer: Die Liste `plugins.php` im Plugin-Ordner
 * (Vorgabe `storage/app/plugins`) nennt je Plugin Version, Handle, PSR-4-Pfade, Dateien und Service-Provider. Beim
 * Start meldet der Lader die Pfade beim Autoloader von Composer an und registriert die Provider. Scheitert ein Plugin
 * dabei, bleibt der Fehler bei ihm: Es fehlt in dieser Anfrage, die Verwaltung zeigt den Grund (`failures()`), die
 * Site läuft weiter.
 *
 * Dazu die Werkstatt (CR-018, E-7): In einem Git-Arbeitsstand mit Ordner `plugins/` lädt der Kern jedes Plugin darin
 * genauso — dieselben Regeln (`PluginPackage`), derselbe Weg. So laufen die eigenen Plugins in Entwicklung und Tests
 * mit; ins Release und ins Produkt-Repository kommt der Ordner nicht.
 *
 * @phpstan-type Record array{version: string, module: string, label: string, psr4: array<string, list<string>>, files: list<string>, providers: list<string>, enabled: bool, installed_at: string, error?: string|null}
 */
final class PluginLoader
{
    public const INDEX = 'plugins.php';

    /** @var array<string, string> Name → Grund, warum es in dieser Anfrage nicht geladen ist */
    private array $failures = [];

    /** @var array<string, true> */
    private array $registered = [];

    public function path(): string
    {
        $configured = config('basalto.plugins.path');

        return rtrim(is_string($configured) && $configured !== '' ? $configured : storage_path('app/plugins'), '/');
    }

    /** Ordner eines Plugins: `<Pfad>/<hersteller>/<paket>`. */
    public function directory(string $name): string
    {
        return $this->path().'/'.$name;
    }

    /** @return array<string, Record> */
    public function all(): array
    {
        $file = $this->path().'/'.self::INDEX;
        if (! is_file($file)) {
            return [];
        }
        try {
            $records = require $file;
        } catch (Throwable) {
            return [];
        }

        return is_array($records) ? $records : [];
    }

    /** @param  array<string, Record>  $records */
    public function save(array $records): void
    {
        ksort($records);
        @mkdir($this->path(), 0775, true);
        $file = $this->path().'/'.self::INDEX;
        $temporary = $file.'.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, "<?php\n\n// Von Basalto geführt (CR-018) — Plugins, die das Backend installiert hat.\n\nreturn ".var_export($records, true).";\n");
        rename($temporary, $file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /** Alle eingeschalteten Plugins anmelden (beim Start, aus dem Kern). */
    public function boot(Application $app): void
    {
        foreach ($this->all() as $name => $record) {
            if (! $record['enabled']) {
                continue;
            }
            try {
                $this->register($app, $name, $record);
            } catch (Throwable $e) {
                $this->failures[$name] = $e->getMessage();
            }
        }
    }

    /** Die Plugins der Werkstatt anmelden (nach `boot()`); ein installiertes gleichen Namens hat Vorrang. */
    public function bootWorkshop(Application $app): void
    {
        $root = $this->workshop();
        foreach ($root === null ? [] : (glob($root.'/*/composer.json') ?: []) as $file) {
            $dir = dirname($file);
            $composer = json_decode((string) file_get_contents($file), true);
            $name = is_array($composer) && is_string($composer['name'] ?? null) ? $composer['name'] : basename($dir);
            try {
                $package = PluginPackage::read($dir, $name, is_array($composer) && is_string($composer['version'] ?? null) ? $composer['version'] : '0.0.0');
                $this->register($app, $name, $package->record(), $dir);
            } catch (Throwable $e) {
                $this->failures[$name] = $e->getMessage();
            }
        }
    }

    /** Der Plugin-Ordner der Werkstatt — nur in einem Git-Arbeitsstand, abschaltbar mit `BASALTO_PLUGINS_WORKSHOP=false`. */
    public function workshop(): ?string
    {
        $path = base_path('plugins');

        return config('basalto.plugins.workshop', true) !== false && is_dir($path) && file_exists(base_path('.git')) ? $path : null;
    }

    /**
     * Ein Plugin anmelden: Klassen, Dateien, Provider. Wirft, wenn etwas fehlt — der Aufrufer entscheidet.
     *
     * @param  array{psr4: array<string, list<string>>, files: list<string>, providers: list<string>}  $record
     * @param  string|null  $dir  Ordner des Plugins, sonst der installierte unter `path()`
     */
    public function register(Application $app, string $name, array $record, ?string $dir = null): void
    {
        if (isset($this->registered[$name])) {
            return;
        }
        $dir ??= $this->directory($name);
        if (! is_dir($dir)) {
            throw new \RuntimeException((string) __('Der Ordner des Plugins fehlt: :path', ['path' => $dir]));
        }
        $loader = self::classLoader();
        foreach ($record['psr4'] as $prefix => $paths) {
            $loader->addPsr4($prefix, array_map(static fn (string $path): string => $dir.'/'.$path, $paths));
        }
        foreach ($record['files'] as $file) {
            require_once $dir.'/'.$file;
        }
        foreach ($record['providers'] as $provider) {
            if (! class_exists($provider)) {
                throw new \RuntimeException((string) __('Der Service-Provider :class fehlt.', ['class' => $provider]));
            }
            $app->register($provider);
        }
        $this->registered[$name] = true;
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function isRegistered(string $name): bool
    {
        return isset($this->registered[$name]);
    }

    /** Der Autoloader von Composer, den die Anwendung schon nutzt. */
    private static function classLoader(): ClassLoader
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            return $loader;
        }
        $loader = new ClassLoader;
        $loader->register();

        return $loader;
    }
}
