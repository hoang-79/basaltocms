<?php

declare(strict_types=1);

namespace Basalto\Core\Backup;

use Basalto\Core\Core;
use Basalto\Core\Http\Controllers\OperationsController;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Transfer\ArchiveWriter;
use Basalto\Core\Transfer\TransferException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sicherung der ganzen Installation (K-17, B-10): Datenbankabzug und Dateien — Mediathek (`sites/` auf jeder Disk der
 * Medien) und Formular-Uploads (`forms/` auf dem privaten Speicher) — in ein ZIP mit Manifest (SHA-256 je Datei).
 * Danach räumt die Aufbewahrung ältere Sicherungen im selben Ordner weg (Einstellung `backup.keep`), und der Stand
 * steht im Cache für die Seite «Betrieb» (`OperationsController::BACKUP_KEY`).
 *
 * @phpstan-type Result array{path: string, size: int, files: int, driver: string, counts: array{sites: int, entries: int, media: int}, removed: list<string>}
 */
final class Backups
{
    public const FORMAT = 'basalto-backup';

    public const FORMAT_VERSION = 1;

    /** Dateinamen der Sicherungen — die Aufbewahrung fasst nur diese an */
    public const PATTERN = 'basalto-backup-*.zip';

    public function __construct(private readonly DatabaseDumper $dumper, private readonly Settings $settings) {}

    public static function directory(?string $directory = null): string
    {
        return rtrim($directory !== null && $directory !== '' ? $directory : storage_path('app/backups'), '/');
    }

    /**
     * @return Result
     *
     * @throws TransferException
     */
    public function create(?string $directory = null): array
    {
        $directory = self::directory($directory);
        $at = CarbonImmutable::now();
        $path = $directory.'/basalto-backup-'.$at->format('Ymd-His').'.zip';
        $work = sys_get_temp_dir().'/basalto-backup-'.Str::random(12);
        if (! @mkdir($work, 0700, true)) {
            throw new TransferException('Arbeitsordner für die Sicherung lässt sich nicht anlegen.');
        }
        $writer = null;
        try {
            $counts = ['sites' => DB::table('sites')->count(), 'entries' => DB::table('entries')->count(), 'media' => DB::table('media')->count()];
            $dump = $this->dumper->dump($work);
            $writer = new ArchiveWriter($path);
            $database = 'database/'.basename($dump['file']);
            $writer->addLocalFile($database, $dump['file']);
            foreach ($this->sources() as [$diskName, $prefix]) {
                $disk = Storage::disk($diskName);
                foreach ($disk->allFiles($prefix) as $file) {
                    $writer->addDiskFile('files/'.$diskName.'/'.$file, $disk, $file);
                }
            }
            $result = $writer->close(['format' => self::FORMAT, 'format_version' => self::FORMAT_VERSION, 'generated_at' => $at->toIso8601String(), 'basalto_version' => Core::VERSION,
                'driver' => $dump['driver'], 'database' => $database, 'counts' => $counts]);
        } catch (Throwable $e) {
            $writer?->discard();

            throw $e;
        } finally {
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
        $removed = $this->prune($directory, $result['path']);
        Cache::forever(OperationsController::BACKUP_KEY, ['at' => $at->toIso8601String(), 'bytes' => $result['size'], 'path' => $result['path'], 'driver' => $dump['driver'], 'files' => $result['files']]);

        return [...$result, 'driver' => $dump['driver'], 'counts' => $counts, 'removed' => $removed];
    }

    /** @return list<string> Sicherungen im Ordner, neueste zuerst */
    public static function list(?string $directory = null): array
    {
        $files = glob(self::directory($directory).'/'.self::PATTERN) ?: [];
        rsort($files);

        return $files;
    }

    /**
     * Welche Dateien zur Sicherung gehören: `sites/` auf jeder Disk, die Medien trägt (und der Standard-Disk), und
     * `forms/` auf dem privaten Speicher.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function sources(): array
    {
        $disks = DB::table('media')->distinct()->pluck('disk')->map(static fn (mixed $d): string => (string) $d)->all();
        $disks[] = (string) config('filesystems.default');
        $sources = [];
        foreach (array_unique($disks) as $disk) {
            $sources[] = [$disk, 'sites'];
        }
        $sources[] = ['local', 'forms'];

        return array_values(array_unique($sources, SORT_REGULAR));
    }

    /** @return list<string> gelöschte ältere Sicherungen über der Aufbewahrung */
    private function prune(string $directory, string $current): array
    {
        $keep = max(1, (int) $this->settings->get('backup.keep', 7));
        $removed = [];
        foreach (array_slice(self::list($directory), $keep) as $file) {
            if ($file !== $current && @unlink($file)) {
                $removed[] = $file;
            }
        }

        return $removed;
    }
}
