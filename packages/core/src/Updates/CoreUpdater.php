<?php

declare(strict_types=1);

namespace Basalto\Core\Updates;

use RuntimeException;

/**
 * Den Kern aus dem Release-Archiv des Herstellers einspielen (CR-007, U-05). Getauscht wird, was das Archiv mitbringt:
 *
 * - `vendor`, `packages` und `modules` gehören ganz dem Release — sie werden als Ganzes getauscht;
 * - alles andere **Datei für Datei**: Was nicht im Archiv steht, bleibt liegen — die SQLite-Datenbank in `database/`,
 *   Caches in `bootstrap/cache`, eigene Themes, eigene Dateien in `public`;
 * - nie angefasst: `.env`, `storage`, `auth.json`, `public/storage`, `public/hot` und jede `*.sqlite`;
 * - `public/.htaccess` bleibt, wenn es sie gibt (CR-011 TB 62): Darin stehen oft Einstellungen des Hostings, etwa die
 *   PHP-Version bei cyon (LiteSpeed erbt sie nur eine Ebene tief) — fehlt sie, kommt die aus dem Release.
 *
 * (Die erste Fassung tauschte jeden Ordner als Ganzes — bei der Probe mit der lokalen Demo wanderte so die
 * SQLite-Datenbank in den Ordner `vorher/`.)
 */
final class CoreUpdater extends ArchiveUpdate
{
    /** Nie anfassen. */
    public const KEEP = ['.env', 'storage', 'auth.json', '.user.ini', '.htaccess'];

    /** Gehören ganz dem Release — als Ganzes tauschen. */
    public const OWNED = ['vendor', 'packages', 'modules'];

    /** Bleibt in `public` (Link auf den Speicher, Vite im Entwicklungsmodus). */
    public const KEEP_PUBLIC = ['public/storage', 'public/hot'];

    /** Bleibt, wenn es sie gibt — sonst aus dem Release. */
    public const KEEP_IF_PRESENT = ['public/.htaccess'];

    /** Höchstens so gross darf ein Release sein. */
    public const MAX_BYTES = 400 * 1024 * 1024;

    public function __construct(Installation $installation, PostSteps $steps, UpdateBackup $backup, private readonly ReleaseManifest $manifest)
    {
        parent::__construct($installation, $steps, $backup);
    }

    protected function target(): string
    {
        return 'core';
    }

    protected function title(): string
    {
        return (string) __('Kern');
    }

    protected function download(string $work, UpdateStatus $status): array
    {
        $release = $this->manifest->fetch();
        if (! Versions::newer($release['version'], $this->installation->version()) && $this->installation->knowsVersion()) {
            throw new RuntimeException((string) __('Basalto ist schon auf dem neusten Stand (:version).', ['version' => $this->installation->version()]));
        }
        $free = @disk_free_space($work);
        if (is_float($free) && $free < $release['size'] * 4) {
            throw new RuntimeException((string) __('Zu wenig Speicherplatz für das Update.'));
        }
        $status->step((string) __('Manifest geprüft: Version :version, signiert', ['version' => $release['version']]));
        $path = $work.'/'.$release['file'];
        $this->fetch(UpdateSource::url('_download/'.$release['file']), $path, self::MAX_BYTES, $release['sha256'], $release['size']);
        $status->step((string) __('Archiv geladen (:mb MB), Prüfsumme stimmt', ['mb' => number_format($release['size'] / 1048576, 1, '.', "'")]));

        return [$path, $release['version']];
    }

    protected function validateStage(string $stage, string $version): void
    {
        foreach (['artisan', 'vendor/autoload.php', 'composer.json', 'packages/core/src/Core.php'] as $file) {
            if (! is_file($stage.'/'.$file)) {
                throw new RuntimeException((string) __('Das Archiv ist kein vollständiges Basalto-Release (:file fehlt).', ['file' => $file]));
            }
        }
        $release = json_decode((string) @file_get_contents($stage.'/'.Installation::RELEASE_FILE), true);
        if (is_array($release) && ($release['version'] ?? null) !== $version) {
            throw new RuntimeException((string) __('Das Archiv gehört nicht zur angebotenen Version.'));
        }
        if (! is_array($release)) {
            // Archive vor CR-007 kennen ihre Version nicht — sie bekommen sie hier
            file_put_contents($stage.'/'.Installation::RELEASE_FILE, (string) json_encode(['product' => 'basalto', 'version' => $version], JSON_PRETTY_PRINT));
        }
    }

    protected function entries(string $stage): array
    {
        $out = [];
        foreach (scandir($stage) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::KEEP, true)) {
                continue;
            }
            if (in_array($entry, self::OWNED, true) || ! is_dir($stage.'/'.$entry)) {
                $out[] = [$entry, $entry];

                continue;
            }
            foreach (self::files($stage, $entry) as $file) {
                if (in_array($file, self::KEEP_IF_PRESENT, true) && file_exists($this->installation->root().'/'.$file)) {
                    continue;
                }
                $out[] = [$file, $file];
            }
        }

        return $out;
    }

    /**
     * Alle Dateien unter einem Ordner des Stage (relativ), ohne Datenbanken und ohne die geschützten Pfade in `public`.
     *
     * @return list<string>
     */
    private static function files(string $stage, string $dir): array
    {
        $out = [];
        foreach (scandir($stage.'/'.$dir) ?: [] as $entry) {
            $path = $dir.'/'.$entry;
            if ($entry === '.' || $entry === '..' || in_array($path, self::KEEP_PUBLIC, true) || str_ends_with($entry, '.sqlite')) {
                continue;
            }
            if (is_dir($stage.'/'.$path) && ! is_link($stage.'/'.$path)) {
                array_push($out, ...self::files($stage, $path));

                continue;
            }
            $out[] = $path;
        }

        return $out;
    }
}
