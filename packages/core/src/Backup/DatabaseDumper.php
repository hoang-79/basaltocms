<?php

declare(strict_types=1);

namespace Basalto\Core\Backup;

use Basalto\Core\Transfer\TransferException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Abzug der Datenbank je Treiber (K-17, B-10): SQLite über `VACUUM INTO` (ein in sich stimmiger Stand, auch während
 * geschrieben wird), MySQL/MariaDB mit `mariadb-dump` oder `mysqldump`, PostgreSQL mit `pg_dump` — beide als
 * SQL-Text, damit die Prüfung Kopf, Fuss und Tabellen lesen kann. Zugangsdaten gehen nie über die Befehlszeile:
 * MySQL liest sie aus einer Optionsdatei (nur für den Besitzer lesbar, danach gelöscht), PostgreSQL aus `PGPASSWORD`.
 * Ein anderer Pfad zum Werkzeug steht in `basalto.backup.binaries.<treiber>`.
 */
final class DatabaseDumper
{
    /** @var array<string, list<string>> Werkzeuge je Treiber, in dieser Reihenfolge gesucht */
    public const BINARIES = ['mysql' => ['mariadb-dump', 'mysqldump'], 'mariadb' => ['mariadb-dump', 'mysqldump'], 'pgsql' => ['pg_dump']];

    /**
     * @return array{driver: string, file: string} Treiber und Pfad der Datei im Arbeitsordner
     *
     * @throws TransferException Werkzeug fehlt, Abzug scheitert, Treiber unbekannt
     */
    public function dump(string $directory, ?string $connection = null): array
    {
        $connection ??= (string) config('database.default');
        /** @var array<string, mixed> $config */
        $config = (array) config('database.connections.'.$connection, []);
        $driver = (string) ($config['driver'] ?? '');

        return match ($driver) {
            'sqlite' => ['driver' => $driver, 'file' => $this->sqlite($connection, $directory)],
            'mysql', 'mariadb' => ['driver' => $driver, 'file' => $this->mysql($driver, $config, $directory)],
            'pgsql' => ['driver' => $driver, 'file' => $this->pgsql($config, $directory)],
            default => throw new TransferException(sprintf('Datenbanktreiber «%s» wird nicht gesichert (SQLite, MySQL/MariaDB, PostgreSQL).', $driver)),
        };
    }

    /** Das Werkzeug eines Treibers: aus der Konfiguration oder dem Suchpfad — null, wenn es fehlt. */
    public static function binary(string $driver): ?string
    {
        $configured = config('basalto.backup.binaries.'.$driver);
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : (new ExecutableFinder)->find($configured);
        }
        foreach (self::BINARIES[$driver] ?? [] as $name) {
            $found = (new ExecutableFinder)->find($name);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function sqlite(string $connection, string $directory): string
    {
        $db = DB::connection($connection);
        if ($db->transactionLevel() > 0) {
            throw new TransferException('SQLite lässt sich nicht innerhalb einer offenen Transaktion sichern (VACUUM INTO) — den Befehl einzeln aufrufen.');
        }
        $target = $directory.'/database.sqlite';
        $db->statement('VACUUM INTO ?', [$target]);

        return $target;
    }

    /** @param  array<string, mixed>  $config */
    private function mysql(string $driver, array $config, string $directory): string
    {
        $binary = self::binary($driver) ?? throw new TransferException('Für die Sicherung fehlt mariadb-dump bzw. mysqldump — Client-Werkzeuge installieren (z. B. Paket «mariadb-client») oder den Pfad in basalto.backup.binaries.'.$driver.' setzen.');
        $target = $directory.'/database.sql';
        $options = $directory.'/client.cnf';
        $lines = ['[client]'];
        foreach (['user' => $config['username'] ?? null, 'password' => $config['password'] ?? null, 'host' => $config['host'] ?? null, 'port' => $config['port'] ?? null, 'socket' => $config['unix_socket'] ?? null] as $key => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $key.'="'.addcslashes((string) $value, '"\\').'"';
            }
        }
        file_put_contents($options, implode("\n", $lines)."\n");
        chmod($options, 0600);
        try {
            $result = Process::timeout(3600)->run([$binary, '--defaults-extra-file='.$options, '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--hex-blob',
                '--default-character-set=utf8mb4', '--result-file='.$target, (string) ($config['database'] ?? '')]);
        } finally {
            @unlink($options);
        }
        if (! $result->successful() || ! is_file($target)) {
            throw new TransferException(sprintf('%s ist gescheitert (Exit %d): %s', basename($binary), (int) $result->exitCode(), trim($result->errorOutput()) ?: 'ohne Meldung'));
        }

        return $target;
    }

    /** @param  array<string, mixed>  $config */
    private function pgsql(array $config, string $directory): string
    {
        $binary = self::binary('pgsql') ?? throw new TransferException('Für die Sicherung fehlt pg_dump — Client-Werkzeuge installieren (z. B. Paket «postgresql-client») oder den Pfad in basalto.backup.binaries.pgsql setzen.');
        $target = $directory.'/database.sql';
        $command = [$binary, '--no-owner', '--no-privileges', '--format=plain', '--file='.$target, '--host='.(string) ($config['host'] ?? '127.0.0.1'), '--port='.(string) ($config['port'] ?? '5432')];
        if (($config['username'] ?? '') !== '') {
            $command[] = '--username='.(string) $config['username'];
        }
        $command[] = '--dbname='.(string) ($config['database'] ?? '');
        $result = Process::timeout(3600)->env(['PGPASSWORD' => (string) ($config['password'] ?? '')])->run($command);
        if (! $result->successful() || ! is_file($target)) {
            throw new TransferException(sprintf('pg_dump ist gescheitert (Exit %d): %s', (int) $result->exitCode(), trim($result->errorOutput()) ?: 'ohne Meldung'));
        }

        return $target;
    }
}
