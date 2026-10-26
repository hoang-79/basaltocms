<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Zeitzone der Datenbank-Session (MariaDB/MySQL). Laravel schreibt UTC-Uhrzeiten als Text; TIMESTAMP-Spalten deuten
 * ihn in der Session-Zeitzone. Seit `DB_TIMEZONE` (Vorgabe `+00:00`) ist die Session UTC — wer vorher auf einem
 * Server in anderer Zeitzone geschrieben hat, liest seine alten Werte um diese Zonendifferenz verschoben.
 * Ohne Option ein Befund; `--umrechnen` setzt alle TIMESTAMP-Spalten der Datenbank einmal auf die neue Deutung und
 * merkt sich das in den Einstellungen, damit ein zweiter Lauf nicht noch einmal verschiebt.
 */
final class DatabaseTimezoneCommand extends Command
{
    public const SETTING = 'database.timezone_converted';

    protected $signature = 'basalto:db-zeitzone
        {--umrechnen : Alle TIMESTAMP-Spalten aus der bisherigen Zeitzone auf UTC umrechnen}
        {--von= : Zeitzone, in der bisher geschrieben wurde (sonst die globale des Servers, z. B. SYSTEM oder +02:00)}
        {--force : Ohne Rückfrage umrechnen}';

    protected $description = 'Zeitzone der Datenbank-Session prüfen und alte TIMESTAMP-Werte auf UTC umrechnen';

    public function handle(Settings $settings): int
    {
        $db = DB::connection();
        $driver = $db->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->info(sprintf('Datenbank %s: Zeitpunkte werden ohne Umrechnung gespeichert, nichts zu tun.', $driver));

            return self::SUCCESS;
        }

        $session = $this->scalar($db, 'SELECT @@session.time_zone AS v');
        $global = $this->scalar($db, 'SELECT @@global.time_zone AS v');
        $system = $this->scalar($db, 'SELECT @@system_time_zone AS v');
        $option = $this->option('von');
        $from = is_string($option) && $option !== '' ? $option : $global;
        $this->line(sprintf('Session: %s · Server: %s (System %s) · bisher geschrieben in: %s', $session, $global, $system, $from));

        $done = $settings->get(self::SETTING);
        if (is_array($done)) {
            $this->info(sprintf('Bereits umgerechnet am %s (von %s) — nichts zu tun.', (string) ($done['at'] ?? '?'), (string) ($done['from'] ?? '?')));

            return self::SUCCESS;
        }

        // Abstand der bisherigen Zone zu UTC im Winter und im Sommer; ohne Zeitzonentabellen kennt der Server nur
        // SYSTEM und feste Abstände wie +02:00
        $winter = $this->scalar($db, "SELECT CONVERT_TZ('2026-01-15 12:00:00', '+00:00', ?) AS v", [$from]);
        $summer = $this->scalar($db, "SELECT CONVERT_TZ('2026-07-15 12:00:00', '+00:00', ?) AS v", [$from]);
        if ($winter === '' || $summer === '') {
            $this->error(sprintf('Die Zeitzone «%s» kennt der Server nicht — geben Sie sie als Abstand an (--von=+01:00) oder als SYSTEM.', $from));

            return self::FAILURE;
        }
        if (str_starts_with($winter, '2026-01-15 12:00:00') && str_starts_with($summer, '2026-07-15 12:00:00')) {
            // TB 64: Mit --umrechnen vermerken, dass nichts umzurechnen ist — sonst empföhle der nächste Befund ohne --von
            // ein Umrechnen aus der Server-Zone (Bestand, der schon in UTC geschrieben wurde)
            if ((bool) $this->option('umrechnen')) {
                $settings->set(self::SETTING, ['from' => $from, 'at' => CarbonImmutable::now()->toIso8601String()]);
                $this->info(sprintf('«%s» entspricht UTC — nichts umzurechnen, vermerkt.', $from));

                return self::SUCCESS;
            }
            $this->info(sprintf('«%s» entspricht UTC — die gespeicherten Werte stimmen, nichts umzurechnen.', $from));

            return self::SUCCESS;
        }

        $columns = $this->timestampColumns($db);
        $count = array_sum(array_map('count', $columns));
        $this->line(sprintf('%d TIMESTAMP-Spalten in %d Tabellen wurden in «%s» geschrieben und erscheinen in der UTC-Session verschoben.', $count, count($columns), $from));

        if (! (bool) $this->option('umrechnen')) {
            $this->line('Umrechnen: php artisan basalto:db-zeitzone --umrechnen (vorher php artisan basalto:backup).');
            $this->line('Bis dahin weiter wie bisher: DB_TIMEZONE=SYSTEM in der .env.');

            return self::SUCCESS;
        }
        if ($session !== '+00:00') {
            $this->error(sprintf('Die Session läuft in «%s», nicht in UTC — entfernen Sie DB_TIMEZONE aus der .env (oder setzen Sie +00:00) und rufen Sie den Befehl erneut auf.', $session));

            return self::FAILURE;
        }
        if (! (bool) $this->option('force') && ! $this->confirm(sprintf('%d Spalten von «%s» auf UTC umrechnen? Legen Sie vorher eine Sicherung an (basalto:backup).', $count, $from))) {
            $this->line('Abgebrochen.');

            return self::FAILURE;
        }

        $grammar = $db->getQueryGrammar();
        $db->transaction(function () use ($db, $grammar, $columns, $from, $settings): void {
            foreach ($columns as $table => $names) {
                // Alle TIMESTAMP-Spalten einer Tabelle in einer Anweisung — so springt kein ON UPDATE CURRENT_TIMESTAMP an
                $sets = implode(', ', array_map(static fn (string $c): string => sprintf("%s = CONVERT_TZ(%s, '+00:00', ?)", $grammar->wrap($c), $grammar->wrap($c)), $names));
                $db->update(sprintf('UPDATE %s SET %s', $grammar->wrap($table), $sets), array_fill(0, count($names), $from));
            }
            // Erst nach dem Umrechnen schreiben — sonst verschöbe es die eigene Zeile gleich mit
            $settings->set(self::SETTING, ['from' => $from, 'at' => CarbonImmutable::now()->toIso8601String()]);
        });
        $this->info(sprintf('%d Spalten in %d Tabellen von «%s» auf UTC umgerechnet.', $count, count($columns), $from));

        return self::SUCCESS;
    }

    /** @return array<string, list<string>> Tabelle → TIMESTAMP-Spalten der aktuellen Datenbank */
    private function timestampColumns(Connection $db): array
    {
        $rows = $db->select("SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
            WHERE c.TABLE_SCHEMA = DATABASE() AND c.DATA_TYPE = 'timestamp' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION");
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row->t][] = (string) $row->c;
        }

        return $columns;
    }

    /** @param  list<mixed>  $bindings */
    private function scalar(Connection $db, string $sql, array $bindings = []): string
    {
        $row = $db->selectOne($sql, $bindings);

        return is_object($row) && isset($row->v) ? (string) $row->v : '';
    }
}
