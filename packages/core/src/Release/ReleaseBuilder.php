<?php

declare(strict_types=1);

namespace Basalto\Core\Release;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Baut das Release-Archiv (CR-004, P-05): die versionierten Dateien ohne Entwicklungsballast, `vendor` ohne
 * Dev-Pakete (Pfad-Pakete kopiert statt verlinkt — ein ZIP kennt keine Symlinks), dazu ein signiertes Manifest
 * `latest.json`. Der Web-Installer braucht danach weder Git noch Composer noch Node.
 *
 * @phpstan-import-type Manifest from ReleaseSignature
 */
final class ReleaseBuilder
{
    /** Ordner, die eine Installation nie braucht. */
    public const EXCLUDE_DIRS = ['dokumentationen/', 'tests/', '.github/', 'tools/', '.ai/', '.claude/', '.agents/', 'node_modules/'];

    /** Dateien der Entwicklung (Build, Prüfung, Editor). */
    public const EXCLUDE_FILES = ['phpunit.xml', 'phpstan.neon', 'pint.json', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml',
        'tsconfig.json', 'vite.config.ts', 'components.json', '.npmrc', '.editorconfig', '.gitattributes', 'AGENTS.md', 'CLAUDE.md', 'boost.json', '.mcp.json'];

    /** Themes, die Kunden bekommen — die übrigen gehören dem Hersteller (basalto, nguyen-development). */
    public const THEMES = ['starter'];

    /** Module nur für den Hersteller oder die Entwicklung: Paket → Ordner. Kunden bekommen KI, Kommentare und Mitglieder. */
    public const VENDOR_MODULES = ['basalto/module-licensing' => 'licensing', 'basalto/module-marketplace' => 'marketplace',
        'basalto/module-testmanagement' => 'testmanagement', 'basalto/module-example' => 'example'];

    /** Startdaten der Entwicklung (Hersteller-Sites, Lighthouse) — nichts für eine Kundeninstallation. */
    public const EXCLUDE_PATHS = ['database/seeders/'];

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @param  non-empty-string  $secretRaw
     * @param  callable(string): void|null  $progress
     * @return Manifest
     */
    public function build(string $source, string $version, string $outDir, string $secretRaw, ?string $vendorFrom = null, bool $store = false, ?callable $progress = null, ?string $notes = null): array
    {
        $progress ??= static function (string $step): void {};
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/', $version) !== 1) {
            throw new RuntimeException('Ungültige Version: '.$version);
        }
        $stage = sys_get_temp_dir().'/basalto-release-'.bin2hex(random_bytes(4));
        $this->files->ensureDirectoryExists($stage);
        try {
            $progress('Dateien sammeln');
            $count = $this->copyTracked($source, $stage);
            $progress(sprintf('%d Dateien', $count));
            if (is_dir($source.'/public/build')) {
                $this->files->copyDirectory($source.'/public/build', $stage.'/public/build');
            }
            $progress('vendor ohne Dev-Pakete');
            $this->vendor($stage, $vendorFrom);
            // CR-007 (U-03): Die Installation kennt so ihre Version — Grundlage der Updates im Backend
            $this->files->put($stage.'/basalto-release.json', (string) json_encode(['product' => 'basalto', 'version' => $version, 'created_at' => now()->toIso8601String()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            $this->files->ensureDirectoryExists($outDir);
            $file = 'basalto-'.$version.'.zip';
            $zipPath = rtrim($outDir, '/').'/'.$file;
            $progress('Archiv packen');
            $this->zip($stage, $zipPath, $store);
        } finally {
            $this->files->deleteDirectory($stage);
        }

        $manifest = ['product' => 'basalto', 'version' => $version, 'file' => $file, 'size' => (int) filesize($zipPath),
            'sha256' => (string) hash_file('sha256', $zipPath), 'php' => '>=8.4', 'created_at' => now()->toIso8601String()];
        $manifest['signature'] = ReleaseSignature::sign($manifest, $secretRaw);
        // CR-007 (U-09): Notizen und Verlauf der letzten Releases — nicht signiert, nur zur Anzeige im Backend
        $manifest['notes'] = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        $manifest['history'] = self::history(rtrim($outDir, '/').'/latest.json', $version);
        $this->files->put(rtrim($outDir, '/').'/latest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $progress('signiert');

        return $manifest;
    }

    /**
     * Verlauf aus dem bisherigen Manifest: dessen Eintrag und seine Geschichte, höchstens 20, ohne die neue Version.
     *
     * @return list<array{version: string, created_at: string|null, notes: string|null}>
     */
    private static function history(string $file, string $version): array
    {
        $previous = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($previous) || ! is_string($previous['version'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ([$previous, ...(is_array($previous['history'] ?? null) ? $previous['history'] : [])] as $entry) {
            if (is_array($entry) && is_string($entry['version'] ?? null) && $entry['version'] !== $version) {
                $out[] = ['version' => $entry['version'], 'created_at' => is_string($entry['created_at'] ?? null) ? $entry['created_at'] : null,
                    'notes' => is_string($entry['notes'] ?? null) ? $entry['notes'] : null];
            }
        }

        return array_slice($out, 0, 20);
    }

    /** Ob ein Pfad ins Release gehört. */
    public static function included(string $path): bool
    {
        foreach ([...self::EXCLUDE_DIRS, ...self::EXCLUDE_PATHS] as $dir) {
            if (str_starts_with($path, $dir)) {
                return false;
            }
        }
        if (preg_match('#^themes/([^/]+)/#', $path, $theme) === 1 && ! in_array($theme[1], self::THEMES, true)) {
            return false;
        }
        if (preg_match('#^modules/([^/]+)/#', $path, $module) === 1 && in_array($module[1], self::VENDOR_MODULES, true)) {
            return false;
        }

        return ! in_array($path, self::EXCLUDE_FILES, true) && ! str_ends_with($path, '.sqlite');
    }

    private function copyTracked(string $source, string $stage): int
    {
        $process = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $source);
        $process->mustRun();
        $count = 0;
        foreach (array_filter(explode("\0", $process->getOutput())) as $path) {
            if (! self::included($path) || ! is_file($source.'/'.$path)) {
                continue;
            }
            $this->files->ensureDirectoryExists(dirname($stage.'/'.$path));
            copy($source.'/'.$path, $stage.'/'.$path);
            $count++;
        }

        return $count;
    }

    private function vendor(string $stage, ?string $vendorFrom): void
    {
        $required = $this->requiredVendorModules($stage);
        if ($vendorFrom !== null) {
            // Schnellweg (Tests, lokale Probe): ein vorhandenes vendor unverändert übernehmen, Symlinks aufgelöst —
            // composer.json ohne die Hersteller-Module, vendor und Sperrdatei bleiben wie sie sind
            $this->withoutVendorModules($stage, $required);
            $this->copyResolved($vendorFrom, $stage.'/vendor');

            return;
        }
        $env = ['COMPOSER_MIRROR_PATH_REPOS' => '1'];
        if ($required !== []) {
            // Hersteller-Module aus composer.json und composer.lock nehmen; installiert wird dabei gleich ohne Dev-Pakete
            (new Process(['composer', 'remove', ...$required, '--no-interaction', '--no-progress', '--no-scripts', '--update-no-dev', '--optimize-autoloader'], $stage, $env, null, 900))->mustRun();

            return;
        }
        (new Process(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist', '--optimize-autoloader', '--no-scripts'], $stage, $env, null, 900))->mustRun();
    }

    /** @return list<string> Hersteller-Module, die composer.json verlangt */
    private function requiredVendorModules(string $stage): array
    {
        $composer = json_decode((string) @file_get_contents($stage.'/composer.json'), true);
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return array_values(array_filter(array_keys(self::VENDOR_MODULES), static fn (string $package): bool => array_key_exists($package, $require)));
    }

    /** @param  list<string>  $packages */
    private function withoutVendorModules(string $stage, array $packages): void
    {
        if ($packages === []) {
            return;
        }
        $composer = (array) json_decode((string) file_get_contents($stage.'/composer.json'), true);
        foreach ($packages as $package) {
            unset($composer['require'][$package]);
        }
        file_put_contents($stage.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function copyResolved(string $from, string $to): void
    {
        $this->files->ensureDirectoryExists($to);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS), RecursiveIteratorIterator::SELF_FIRST);
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? $this->files->ensureDirectoryExists($target) : copy($item->getPathname(), $target);
        }
    }

    private function zip(string $stage, string $zipPath, bool $store): void
    {
        @unlink($zipPath);
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Archiv lässt sich nicht anlegen: '.$zipPath);
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS));
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $name = substr($item->getPathname(), strlen($stage) + 1);
            $zip->addFile($item->getPathname(), $name);
            if ($store) {
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        }
        if (! $zip->close()) {
            throw new RuntimeException('Archiv lässt sich nicht schreiben: '.$zipPath);
        }
    }
}
