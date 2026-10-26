<?php

declare(strict_types=1);

namespace Basalto\Core\Backup;

use Basalto\Core\Http\Controllers\OperationsController;
use Basalto\Core\Transfer\ArchiveReader;
use Basalto\Core\Transfer\TransferException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Wiederherstellungsprüfung einer Sicherung (K-17, B-10): Manifest und SHA-256 jeder Datei; bei SQLite wird die
 * Datenbank in eine temporäre Datei zurückgespielt und mit `PRAGMA integrity_check` und einer Zählprobe (Sites,
 * Inhalte, Medien gegen das Manifest) geöffnet; bei MySQL/MariaDB und PostgreSQL wird der Abzug auf Vollständigkeit
 * gelesen — Kopf, Fuss («Dump completed» bzw. «dump complete») und die Kerntabellen. Das Ergebnis steht im Cache für
 * die Seite «Betrieb» (`OperationsController::BACKUP_VERIFIED_KEY`), auch wenn die Prüfung scheitert.
 *
 * @phpstan-type Result array{ok: bool, driver: string, archive: string, checks: list<string>, counts: array<string, int>}
 */
final class BackupVerifier
{
    /** @var list<string> Tabellen, ohne die keine Installation wiederhergestellt ist */
    public const REQUIRED_TABLES = ['migrations', 'users', 'accounts', 'sites', 'site_locales', 'entries', 'entry_versions', 'media', 'settings'];

    /** @return Result */
    public function verify(string $archive): array
    {
        $checks = [];
        $driver = '?';
        $counts = [];
        try {
            $reader = new ArchiveReader($archive);
            $manifest = $reader->verify();
            if (($manifest['format'] ?? null) !== Backups::FORMAT) {
                throw new TransferException('Keine Sicherung von Basalto (format «'.Backups::FORMAT.'» erwartet).');
            }
            $files = $reader->files();
            $checks[] = sprintf('Manifest und SHA-256 von %d Dateien stimmen', count($files));
            $driver = (string) ($manifest['driver'] ?? '?');
            $database = (string) ($manifest['database'] ?? '');
            if (! $reader->has($database)) {
                throw new TransferException('Der Datenbankabzug fehlt in der Sicherung.');
            }
            $expected = is_array($manifest['counts'] ?? null) ? $manifest['counts'] : [];
            $temp = sys_get_temp_dir().'/basalto-verify-'.Str::random(12).'.'.pathinfo($database, PATHINFO_EXTENSION);
            try {
                $reader->extractTo($database, $temp);
                [$more, $counts] = $driver === 'sqlite' ? $this->sqlite($temp, $expected) : $this->sqlDump($temp, $driver);
                $checks = [...$checks, ...$more];
            } finally {
                @unlink($temp);
            }
            $result = ['ok' => true, 'driver' => $driver, 'archive' => $archive, 'checks' => $checks, 'counts' => $counts];
        } catch (TransferException $e) {
            $result = ['ok' => false, 'driver' => $driver, 'archive' => $archive, 'checks' => [...$checks, 'Fehler: '.$e->getMessage()], 'counts' => $counts];
        }
        // «restored»: bei SQLite wirklich zurückgespielt und geöffnet, sonst nur der Abzug gelesen
        Cache::forever(OperationsController::BACKUP_VERIFIED_KEY, ['at' => CarbonImmutable::now()->toIso8601String(), 'ok' => $result['ok'], 'driver' => $driver,
            'restored' => $result['ok'] && $driver === 'sqlite', 'archive' => basename($archive), 'checks' => $result['checks']]);

        return $result;
    }

    /**
     * Zurückspielen in eine temporäre Datei und öffnen.
     *
     * @param  array<string, mixed>  $expected  Zählungen aus dem Manifest
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private function sqlite(string $file, array $expected): array
    {
        try {
            $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $integrity = (string) self::query($pdo, 'PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new TransferException('PRAGMA integrity_check meldet: '.$integrity);
            }
            $tables = self::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            $this->requireTables(array_values(array_map('strval', $tables)));
            $counts = [];
            foreach (['sites', 'entries', 'media'] as $table) {
                $counts[$table] = (int) self::query($pdo, 'SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
                if (isset($expected[$table]) && (int) $expected[$table] !== $counts[$table]) {
                    throw new TransferException(sprintf('Zählprobe %s: %d in der Datenbank, %d beim Sichern.', $table, $counts[$table], (int) $expected[$table]));
                }
            }
            $pdo = null;
        } catch (TransferException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransferException('Die gesicherte SQLite-Datenbank lässt sich nicht öffnen: '.$e->getMessage());
        }

        return [['Datenbank zurückgespielt, PRAGMA integrity_check: ok', sprintf('Zählprobe: %d Sites, %d Inhalte, %d Medien — wie beim Sichern', $counts['sites'], $counts['entries'], $counts['media'])], $counts];
    }

    /**
     * SQL-Abzug lesen: Kopf, Fuss, Kerntabellen, Zahl der Tabellen.
     *
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private function sqlDump(string $file, string $driver): array
    {
        $postgres = $driver === 'pgsql';
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new TransferException('Der Datenbankabzug lässt sich nicht lesen.');
        }
        $head = (string) fread($handle, 4096);
        $size = (int) filesize($file);
        fseek($handle, max(0, $size - 4096));
        $tail = (string) fread($handle, 4096);
        rewind($handle);
        $tables = [];
        $pattern = $postgres ? '/^CREATE TABLE (?:"?[a-z_]+"?\.)?"?([a-z0-9_]+)"? \(/' : '/^CREATE TABLE `([a-z0-9_]+)`/';
        while (($line = fgets($handle)) !== false) {
            if (str_starts_with($line, 'CREATE TABLE') && preg_match($pattern, $line, $m) === 1) {
                $tables[] = $m[1];
            }
        }
        fclose($handle);
        if ($postgres ? ! str_contains($head, '-- PostgreSQL database dump') : preg_match('/-- (MariaDB|MySQL) dump/', $head) !== 1) {
            throw new TransferException('Kopf des Abzugs fehlt — keine Ausgabe von '.($postgres ? 'pg_dump' : 'mysqldump/mariadb-dump').'.');
        }
        if ($postgres ? ! str_contains($tail, '-- PostgreSQL database dump complete') : ! str_contains($tail, '-- Dump completed')) {
            throw new TransferException('Der Abzug ist abgeschnitten — die Schlusszeile fehlt.');
        }
        $this->requireTables($tables);

        return [[sprintf('Abzug vollständig: Kopf und Schlusszeile vorhanden, %d Tabellen, Kerntabellen da', count($tables)), 'Rückspielprobe in eine leere Datenbank: von Hand (Anleitung backup-und-wiederherstellung.md)'],
            ['tables' => count($tables)]];
    }

    private static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new TransferException('Abfrage auf der gesicherten Datenbank gescheitert: '.$sql);
        }

        return $statement;
    }

    /** @param  list<string>  $tables */
    private function requireTables(array $tables): void
    {
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $tables));
        if ($missing !== []) {
            throw new TransferException('Im Abzug fehlen Tabellen: '.implode(', ', $missing));
        }
    }
}
