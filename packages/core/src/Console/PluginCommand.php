<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Plugins\PluginInstaller;
use Basalto\Core\Plugins\PluginLoader;
use Basalto\Core\Plugins\PluginPageCheck;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Plugins aus dem Marktplatz ohne Composer (CR-018, TB 98) — dasselbe wie im Backend unter Verwaltung › Module, für
 * die Kommandozeile: installieren, aktualisieren, ein- und ausschalten, entfernen, auflisten. Dazu (TB 100): mit
 * `--archiv` aus einem Archiv auf diesem Server installieren und mit `check` die Seiten eines Plugins aufrufen — so
 * erproben Autorinnen ihr Plugin, und so läuft der Prüfstand in der Sandbox des Marktplatzes.
 */
final class PluginCommand extends Command
{
    protected $signature = 'basalto:plugin
        {aktion : install, update, enable, disable, remove, check oder list}
        {name? : Plugin als hersteller/paket}
        {--archiv= : beim Installieren: ZIP auf diesem Server statt aus dem Marktplatz (ohne Signatur)}
        {--daten-loeschen : beim Entfernen auch die Tabellen des Plugins löschen}
        {--benutzer= : bei check: angemeldet als diese E-Mail (sonst Inhaber der Standard-Site)}
        {--json : bei check: Ergebnis als JSON}';

    protected $description = 'Plugins aus dem Marktplatz installieren, aktualisieren, schalten oder entfernen (ohne Composer)';

    public function handle(PluginInstaller $installer, PluginLoader $loader, PluginPageCheck $pages): int
    {
        $action = (string) $this->argument('aktion');
        if ($action === 'list') {
            $rows = [];
            foreach ($loader->all() as $name => $record) {
                $rows[] = [$name, $record['label'], $record['version'], $record['enabled'] ? 'an' : 'aus', $loader->failures()[$name] ?? ($record['error'] ?? '')];
            }
            if ($rows === []) {
                $this->info('Keine Plugins im Backend installiert.');
            } else {
                $this->table(['Plugin', 'Name', 'Version', 'Stand', 'Hinweis'], $rows);
            }

            return self::SUCCESS;
        }
        $name = (string) $this->argument('name');
        $archive = (string) $this->option('archiv');
        if ($action === 'install' && $archive !== '') {
            return $this->report(fn (): string => $installer->installArchive($archive, 'Kommandozeile'));
        }
        if (! in_array($action, ['install', 'update', 'enable', 'disable', 'remove', 'check'], true) || $name === '') {
            $this->error('Aktion install, update, enable, disable, remove, check oder list — ausser bei list (und install mit --archiv) mit dem Namen des Plugins.');

            return self::INVALID;
        }
        if ($action === 'check') {
            return $this->check($pages, $name);
        }

        return $this->report(fn (): string => match ($action) {
            'install' => $installer->install($name, 'Kommandozeile'),
            'update' => $installer->update($name, 'Kommandozeile'),
            'enable' => $installer->enable($name, true, 'Kommandozeile'),
            'disable' => $installer->enable($name, false, 'Kommandozeile'),
            default => $installer->remove($name, (bool) $this->option('daten-loeschen'), 'Kommandozeile'),
        });
    }

    /** @param  callable(): string  $step */
    private function report(callable $step): int
    {
        try {
            $message = $step();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info($message);

        return self::SUCCESS;
    }

    /** Die Seiten des Plugins aufrufen; scheitert, sobald eine mit einem Serverfehler antwortet. */
    private function check(PluginPageCheck $pages, string $name): int
    {
        try {
            $results = $pages->run($name, (string) $this->option('benutzer'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $failed = array_values(array_filter($results, static fn (array $r): bool => $r['status'] >= 500));
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['pages' => $results, 'failed' => count($failed)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($results === []) {
            $this->info('Keine Seiten ohne Parameter gefunden.');
        } else {
            $this->table(['Seite', 'Antwort', 'Fehler'], array_map(static fn (array $r): array => [$r['uri'], $r['status'], $r['error'] ?? ''], $results));
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
