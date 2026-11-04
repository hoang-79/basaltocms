<?php

declare(strict_types=1);

namespace Basalto\Core\Transfer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabellen von Modulen und Plugins im Site-Export (CR-018, TB 101). Ein Modul meldet beim Booten an, was mit seinen
 * Tabellen mit `site_id` geschieht:
 *
 * - `export()`: eine einfache Tabelle — ihre Zeilen verweisen nur über `site_id` auf anderes. Der Export nimmt sie ohne
 *   `id` und `site_id` mit, der Import legt sie für die neue Site an, wenn das Modul dort installiert ist.
 * - `exclude()`: bleibt begründet zurück, wie die Ausnahmen des Kerns (`SiteExporter::EXCLUDED`).
 *
 * Die Anmeldungen stehen in der Konfiguration statt in einer statischen Eigenschaft (Octane), wie die Einhängepunkte.
 */
final class ModuleTables
{
    public const CONFIG = 'basalto.transfer.module_tables';

    public function export(string $table): void
    {
        config()->set(self::CONFIG.'.'.$table, ['export' => true, 'reason' => null]);
    }

    public function exclude(string $table, string $reason): void
    {
        config()->set(self::CONFIG.'.'.$table, ['export' => false, 'reason' => $reason]);
    }

    /** @return list<string> */
    public function exported(): array
    {
        return array_keys(array_filter($this->all(), static fn (array $entry): bool => $entry['export']));
    }

    /** @return array<string, string> Tabelle → Grund */
    public function excluded(): array
    {
        $out = [];
        foreach ($this->all() as $table => $entry) {
            if (! $entry['export']) {
                $out[$table] = (string) $entry['reason'];
            }
        }

        return $out;
    }

    /**
     * Die Zeilen der angemeldeten Tabellen einer Site, ohne `id` und `site_id`; Wahrheitswerte als `true`/`false`,
     * damit sie auf jeder Datenbank wieder passen.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function rows(int $siteId): array
    {
        $out = [];
        foreach ($this->exported() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $booleans = self::booleans($table);
            $out[$table] = [];
            foreach (DB::table($table)->where('site_id', $siteId)->orderBy('id')->get() as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;
                unset($values['id'], $values['site_id']);
                foreach ($booleans as $column) {
                    if (array_key_exists($column, $values) && $values[$column] !== null) {
                        $values[$column] = (bool) $values[$column];
                    }
                }
                $out[$table][] = $values;
            }
        }

        return $out;
    }

    /**
     * Zeilen für eine neue Site anlegen — nur Tabellen, die hier angemeldet sind und bestehen, nur Spalten, die es gibt.
     *
     * @param  array<mixed>  $tables  Tabelle → Zeilen aus dem Export
     * @param  list<string>  $notes
     * @return int angelegte Zeilen
     */
    public function import(array $tables, int $siteId, array &$notes): int
    {
        $count = 0;
        $exported = $this->exported();
        foreach ($tables as $table => $rows) {
            if (! is_string($table) || ! is_array($rows) || $rows === []) {
                continue;
            }
            if (! in_array($table, $exported, true) || ! Schema::hasTable($table)) {
                $notes[] = sprintf('Tabelle «%s» (%d Zeilen) nicht übernommen: Das Modul dazu ist hier nicht installiert.', $table, count($rows));

                continue;
            }
            $columns = array_flip(Schema::getColumnListing($table));
            unset($columns['id'], $columns['site_id']);
            foreach ($rows as $row) {
                if (is_array($row)) {
                    DB::table($table)->insert(['site_id' => $siteId] + array_intersect_key($row, $columns));
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return array<string, array{export: bool, reason: string|null}> */
    private function all(): array
    {
        $out = [];
        foreach ((array) config(self::CONFIG, []) as $table => $entry) {
            if (is_string($table) && is_array($entry)) {
                $out[$table] = ['export' => ($entry['export'] ?? false) === true, 'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : null];
            }
        }

        return $out;
    }

    /** @return list<string> Spalten mit Wahrheitswerten (SQLite, MySQL und MariaDB: tinyint(1), PostgreSQL: boolean) */
    private static function booleans(string $table): array
    {
        $out = [];
        foreach (Schema::getColumns($table) as $column) {
            if (in_array(strtolower((string) $column['type_name']), ['bool', 'boolean'], true) || strtolower((string) $column['type']) === 'tinyint(1)') {
                $out[] = (string) $column['name'];
            }
        }

        return $out;
    }
}
