<?php

declare(strict_types=1);

namespace Basalto\Core\Plugins;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Core;
use Basalto\Core\Marketplace\MarketplaceClient;
use Basalto\Core\Updates\ArchiveDownload;
use Basalto\Core\Updates\ArchiveUpdate;
use Basalto\Core\Updates\Installation;
use Basalto\Core\Updates\PluginArchive;
use Basalto\Core\Updates\UpdateBackup;
use Basalto\Core\Updates\Versions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Plugins aus dem Marktplatz im Backend installieren, aktualisieren, ein- und ausschalten und entfernen (CR-018, TB 98)
 * — ohne Composer und ohne eigenen Prozess: Auf vielen Hostings (cyon) darf PHP im Browser keine Prozesse starten.
 * Das geht, weil eine Installation keinen Code tauscht, den die laufende Anfrage schon geladen hat — sie legt einen
 * neuen Ordner an. Ablauf der Installation:
 *
 * 1. signiertes Archiv laden (E-Mail und Token des Kundenkontos), Prüfsumme und Signatur des Marktplatzes prüfen
 * 2. entpacken, Form und Verträglichkeit prüfen (`PluginPackage`)
 * 3. Sicherung (Datenbank und Medien, wie beim Update)
 * 4. Ordner an seinen Platz, Plugin in dieser Anfrage anmelden, migrieren, Assets veröffentlichen
 * 5. erst jetzt in die Liste — ab der nächsten Anfrage lädt es jeder Prozess
 * 6. Startprobe: `/up/live` über die eigene Adresse in einem frischen Prozess; startet die Site nicht, schaltet Basalto
 *    das Plugin gleich wieder aus
 *
 * Scheitert 4, kommen Tabellen und Ordner wieder weg.
 */
final class PluginInstaller
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly Application $app,
        private readonly Installation $installation,
        private readonly PluginLoader $loader,
        private readonly MarketplaceClient $marketplace,
        private readonly UpdateBackup $backup,
        private readonly Recorder $recorder,
    ) {}

    /**
     * Was dem Installieren im Backend im Weg steht — leer heisst: es geht.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->installation->mode() !== 'archiv') {
            $problems[] = $this->installation->mode() === 'git'
                ? (string) __('Diese Installation ist ein Git-Arbeitsstand — Plugins kommen hier mit Composer.')
                : (string) __('Diese Installation wird mit Composer gepflegt — Plugins kommen hier mit Composer.');
        }
        if (! class_exists('ZipArchive')) {
            $problems[] = (string) __('Die PHP-Erweiterung «zip» fehlt.');
        }
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            $problems[] = (string) __('Die PHP-Erweiterung «sodium» fehlt.');
        }
        $path = $this->loader->path();
        $writable = is_dir($path) ? $path : dirname($path);
        if (! is_dir($writable) || ! is_writable($writable)) {
            $problems[] = (string) __('Kein Schreibrecht auf :path.', ['path' => $writable]);
        }

        return $problems;
    }

    /** Installieren; gibt die Meldung für die Verwaltung zurück. */
    public function install(string $name, ?string $by = null): string
    {
        $this->guard($name);

        return $this->locked(function () use ($name, $by): string {
            $this->ensureNew($name);
            $entry = $this->entry($name);
            $work = $this->work();
            try {
                $package = $this->fetch($name, $entry['version'], $entry['sha256'], $work);

                return $this->place($package, $work.'/neu', $by);
            } finally {
                ArchiveUpdate::remove($work);
            }
        });
    }

    /**
     * Aus einem Archiv auf diesem Server installieren — ohne Katalog und ohne Signatur des Marktplatzes (TB 100). Nur
     * über die Kommandozeile: zum Ausprobieren eines eigenen Plugins und für den Prüfstand-Lauf in der Sandbox. Sonst
     * wie `install()`: Form, Verträglichkeit, Sicherung, Migrationen, Startprobe.
     */
    public function installArchive(string $archive, ?string $by = null): string
    {
        $problems = $this->problems();
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }
        if (! is_file($archive)) {
            throw new RuntimeException((string) __('Das Archiv :path gibt es nicht.', ['path' => $archive]));
        }

        return $this->locked(function () use ($archive, $by): string {
            $work = $this->work();
            try {
                ArchiveDownload::extract($archive, $work.'/auspacken');
                $dir = PluginPackage::rootIn($work.'/auspacken') ?? throw new RuntimeException((string) __('Im Archiv fehlt eine gültige composer.json.'));
                $composer = json_decode((string) file_get_contents($dir.'/composer.json'), true);
                $name = is_array($composer) && is_string($composer['name'] ?? null) ? $composer['name'] : '';
                $version = is_array($composer) && is_string($composer['version'] ?? null) ? $composer['version'] : '';
                if ($version === '') {
                    throw new RuntimeException((string) __('In composer.json fehlt die Version.'));
                }
                $package = PluginPackage::read($dir, $name, $version);
                $problems = $package->problems(Core::VERSION);
                if ($problems !== []) {
                    throw new RuntimeException(implode(' ', $problems));
                }
                $this->ensureNew($package->name);

                return $this->place($package, $dir, $by);
            } finally {
                ArchiveUpdate::remove($work);
            }
        });
    }

    /** Ein im Backend installiertes Plugin aktualisieren — bei einem Fehler kommt die alte Fassung zurück. */
    public function update(string $name, ?string $by = null): string
    {
        $this->guard($name);

        return $this->locked(function () use ($name, $by): string {
            $records = $this->loader->all();
            $old = $records[$name] ?? throw new RuntimeException((string) __(':name wurde nicht im Backend installiert.', ['name' => $name]));
            $entry = $this->entry($name);
            if (! Versions::newer($entry['version'], $old['version'])) {
                throw new RuntimeException((string) __('Für :name gibt es keine neuere Version.', ['name' => $name]));
            }
            $work = $this->work();
            $target = $this->loader->directory($name);
            try {
                $package = $this->fetch($name, $entry['version'], $entry['sha256'], $work);
                $this->backup->create($this->installation->root().'/storage/app/backups');
                if (! rename($target, $work.'/vorher') || ! rename($work.'/neu', $target)) {
                    throw new RuntimeException((string) __('Die Dateien des Plugins lassen sich nicht tauschen.'));
                }
                $restore = function () use ($target, $work, $records): void {
                    ArchiveUpdate::remove($target);
                    @rename($work.'/vorher', $target);
                    $this->loader->save($records);
                    $this->clearCaches();
                };
                try {
                    $this->artisan('migrate', ['--force' => true]);
                    $this->artisan('vendor:publish', ['--tag' => 'basalto-assets', '--force' => true]);
                } catch (Throwable $e) {
                    $restore();

                    throw new RuntimeException((string) __('Update zurückgenommen: :reason', ['reason' => $e->getMessage()]), 0, $e);
                }
                $updated = $records;
                $updated[$name] = $package->record() + ['enabled' => $old['enabled'], 'installed_at' => $old['installed_at']];
                $this->loader->save($updated);
                $this->clearCaches();
                $failure = $old['enabled'] ? $this->startProbe() : null;
                if ($failure !== null) {
                    $restore();

                    throw new RuntimeException((string) __('Update zurückgenommen: Die Site startet mit :version nicht (:reason).', ['version' => $package->version, 'reason' => $failure]));
                }
                $this->recorder->record('system.plugin_updated', null, null, ['plugin' => $name, 'version' => $package->version, 'vorher' => $old['version'], 'von' => $by]);

                return (string) __(':label ist auf :version aktualisiert.', ['label' => $package->label, 'version' => $package->version]);
            } finally {
                ArchiveUpdate::remove($work);
            }
        });
    }

    /** Ein- oder ausschalten — für die ganze Installation (je Site schaltet die Verwaltung der Site). */
    public function enable(string $name, bool $enabled, ?string $by = null): string
    {
        return $this->locked(function () use ($name, $enabled, $by): string {
            $records = $this->loader->all();
            $record = $records[$name] ?? throw new RuntimeException((string) __(':name wurde nicht im Backend installiert.', ['name' => $name]));
            $record['enabled'] = $enabled;
            $record['error'] = null;
            $records[$name] = $record;
            $this->loader->save($records);
            $this->clearCaches();
            if ($enabled) {
                $failure = $this->startProbe();
                if ($failure !== null) {
                    $record['enabled'] = false;
                    $record['error'] = $failure;
                    $records[$name] = $record;
                    $this->loader->save($records);
                    $this->clearCaches();

                    throw new RuntimeException((string) __(':label bleibt aus: Die Site startet damit nicht (:reason).', ['label' => $record['label'], 'reason' => $failure]));
                }
            }
            $this->recorder->record($enabled ? 'system.plugin_enabled' : 'system.plugin_disabled', null, null, ['plugin' => $name, 'von' => $by]);

            return $enabled
                ? (string) __(':label ist eingeschaltet.', ['label' => $record['label']])
                : (string) __(':label ist ausgeschaltet — seine Daten bleiben.', ['label' => $record['label']]);
        });
    }

    /** Entfernen; mit `$deleteData` auch die Tabellen des Plugins (seine Migrationen rückwärts). */
    public function remove(string $name, bool $deleteData, ?string $by = null): string
    {
        return $this->locked(function () use ($name, $deleteData, $by): string {
            $records = $this->loader->all();
            $record = $records[$name] ?? throw new RuntimeException(array_key_exists($name, $this->installation->plugins())
                ? (string) __(':name ist mit Composer installiert — entfernen Sie es mit «composer remove :name».', ['name' => $name])
                : (string) __(':name wurde nicht im Backend installiert.', ['name' => $name]));
            $target = $this->loader->directory($name);
            if ($deleteData) {
                $this->backup->create($this->installation->root().'/storage/app/backups');
                $this->resetMigrations($target);
            }
            unset($records[$name]);
            $this->loader->save($records);
            ArchiveUpdate::remove($target);
            $vendorDir = dirname($target);
            if (is_dir($vendorDir) && (scandir($vendorDir) ?: []) === ['.', '..']) {
                @rmdir($vendorDir);
            }
            $this->clearCaches();
            $this->recorder->record('system.plugin_removed', null, null, ['plugin' => $name, 'version' => $record['version'], 'daten' => $deleteData, 'von' => $by]);

            return $deleteData
                ? (string) __(':label ist entfernt, seine Tabellen sind gelöscht (eine Sicherung liegt unter Betrieb).', ['label' => $record['label']])
                : (string) __(':label ist entfernt — seine Daten bleiben in der Datenbank, falls Sie es wieder installieren.', ['label' => $record['label']]);
        });
    }

    private function ensureNew(string $name): void
    {
        if (array_key_exists($name, $this->installation->plugins())) {
            throw new RuntimeException((string) __(':name ist schon installiert.', ['name' => $name]));
        }
    }

    /** Schritte 3 bis 6: Sicherung, Ordner an seinen Platz, anmelden, migrieren, in die Liste, Startprobe. */
    private function place(PluginPackage $package, string $source, ?string $by): string
    {
        $name = $package->name;
        $this->backup->create($this->installation->root().'/storage/app/backups');
        $target = $this->loader->directory($name);
        ArchiveUpdate::remove($target);
        @mkdir(dirname($target), 0775, true);
        if (! rename($source, $target)) {
            throw new RuntimeException((string) __('Der Ordner des Plugins lässt sich nicht anlegen.'));
        }
        $record = $package->record() + ['enabled' => true, 'installed_at' => now()->toIso8601String()];
        try {
            $this->loader->register($this->app, $name, $record);
            $this->artisan('migrate', ['--force' => true]);
            $this->artisan('vendor:publish', ['--tag' => 'basalto-assets', '--force' => true]);
        } catch (Throwable $e) {
            $this->resetMigrations($target);
            ArchiveUpdate::remove($target);

            throw new RuntimeException((string) __('Installation zurückgenommen: :reason', ['reason' => $e->getMessage()]), 0, $e);
        }
        $records = $this->loader->all();
        $records[$name] = $record;
        $this->loader->save($records);
        $this->clearCaches();
        $failure = $this->startProbe();
        if ($failure !== null) {
            $records[$name]['enabled'] = false;
            $records[$name]['error'] = $failure;
            $this->loader->save($records);
            $this->clearCaches();
            $this->recorder->record('system.plugin_installed', null, null, ['plugin' => $name, 'version' => $package->version, 'von' => $by, 'aus' => $failure]);

            return (string) __(':label :version ist installiert, aber ausgeschaltet: Die Site startet damit nicht (:reason).', ['label' => $package->label, 'version' => $package->version, 'reason' => $failure]);
        }
        $this->recorder->record('system.plugin_installed', null, null, ['plugin' => $name, 'version' => $package->version, 'von' => $by]);

        return (string) __(':label :version ist installiert. Schalten Sie es unter Verwaltung › Sites für die Sites ein, die es nutzen sollen.', ['label' => $package->label, 'version' => $package->version]);
    }

    private function guard(string $name): void
    {
        if (preg_match(PluginPackage::NAME, $name) !== 1) {
            throw new RuntimeException((string) __('Kein gültiger Plugin-Name.'));
        }
        $problems = $this->problems();
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        // Dieselbe Sperre wie die Updates: nie zwei Eingriffe gleichzeitig
        $dir = $this->installation->root().'/storage/app/updates';
        @mkdir($dir, 0775, true);
        $lock = fopen($dir.'/.sperre', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException((string) __('Es läuft schon ein Update.'));
        }
        @set_time_limit(600);
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{version: string, sha256: string|null} */
    private function entry(string $name): array
    {
        foreach ($this->marketplace->catalog(true)['entries'] as $candidate) {
            if ($candidate['kind'] === 'plugin' && $candidate['name'] === $name) {
                return ['version' => $candidate['version'], 'sha256' => $candidate['sha256']];
            }
        }

        throw new RuntimeException((string) __('Dieses Plugin gibt es im Marktplatz nicht (mehr).'));
    }

    private function work(): string
    {
        $work = $this->loader->path().'/.lauf-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
        @mkdir($work, 0775, true);

        return $work;
    }

    /** Laden, Signatur prüfen, entpacken nach `<work>/neu`, Paket lesen und Verträglichkeit prüfen. */
    private function fetch(string $name, string $version, ?string $sha256, string $work): PluginPackage
    {
        $credentials = $this->marketplace->credentials();
        if ($credentials === null) {
            throw new RuntimeException((string) __('Verbinden Sie zuerst Ihr Kundenkonto von :host (Verwaltung › Module › Marktplatz).', ['host' => $this->marketplace->host()]));
        }
        $zip = $work.'/plugin.zip';
        ArchiveDownload::fetch($this->marketplace->baseUrl().'/composer/dist/'.$name.'/'.rawurlencode($version).'.zip', $zip, self::MAX_BYTES, $sha256, null, $credentials);
        PluginArchive::verify($zip, $name, $version, (string) config('basalto.marketplace.public_key'));
        ArchiveDownload::extract($zip, $work.'/neu');
        @unlink($work.'/neu/basalto-manifest.json');
        @unlink($work.'/neu/basalto-signature');
        $package = PluginPackage::read($work.'/neu', $name, $version);
        $problems = $package->problems(Core::VERSION);
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        return $package;
    }

    /** @param  array<string, mixed>  $arguments */
    private function artisan(string $command, array $arguments): void
    {
        $exit = Artisan::call($command, $arguments);
        if ($exit !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: (string) __(':command ist gescheitert.', ['command' => $command]));
        }
    }

    /** Die Migrationen des Plugins rückwärts (Ordner `database/migrations` im Paket). */
    /**
     * Die Tabellen des Plugins löschen: seine Migrationen rückwärts. Nicht über «migrate:reset» — das ist in Produktion
     * gesperrt (prohibitDestructiveCommands) und bräche still ab. Hier ist es gewollt: Die Sicherung liegt schon an, und
     * wer entfernt, hat «Daten löschen» ausdrücklich gewählt. Geht es schief, bleibt das Plugin, die Meldung sagt warum.
     */
    private function resetMigrations(string $target): void
    {
        $migrations = $target.'/database/migrations';
        $migrator = app('migrator');
        if (! is_dir($migrations) || ! $migrator->repositoryExists()) {
            return;
        }
        try {
            $migrator->reset([$migrations]);
        } catch (Throwable $e) {
            throw new RuntimeException((string) __('Die Tabellen des Plugins liessen sich nicht löschen: :message', ['message' => $e->getMessage()]), 0, $e);
        }
    }

    private function clearCaches(): void
    {
        try {
            Artisan::call('optimize:clear');
        } catch (Throwable) {
            // nicht zwingend: ohne Caches liest Laravel alles frisch
        }
    }

    /**
     * Startet die Site mit der neuen Liste? `/up/live` über die eigene Adresse — das beantwortet ein frischer Prozess,
     * der alle Plugins lädt. Antwortet sie nicht (Verbindung), entscheidet die Probe nichts.
     */
    private function startProbe(): ?string
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)->withUserAgent('Basalto/'.Core::VERSION.' Startprobe')->get(route('basalto.up.live'));
        } catch (ConnectionException) {
            return null;
        }
        if ($response->serverError()) {
            return (string) __('Antwort :status', ['status' => $response->status()]);
        }

        return null;
    }
}
