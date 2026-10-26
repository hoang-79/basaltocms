<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Demo\DemoMode;
use Basalto\Core\Demo\DemoReset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `basalto:demo-reset` (TB 11 Scheibe 4, G-10): die öffentliche Demo neu aufsetzen — Datenbank frisch, Demo-Site mit
 * Inhalten, drei Konten für den Schnellzugang. Löscht alles in der Datenbank der Installation; darum doppelt gesichert:
 * nur mit `BASALTO_DEMO=true` und nur mit Bestätigung oder `--force`. Der Scheduler ruft ihn im Demo-Modus jede Nacht.
 */
final class DemoResetCommand extends Command
{
    protected $signature = 'basalto:demo-reset {--force : Ohne Rückfrage (Scheduler)}';

    protected $description = 'Demo neu aufsetzen (nur mit BASALTO_DEMO=true): Datenbank frisch, Demo-Site, drei Konten';

    public function handle(DemoReset $reset): int
    {
        if (! DemoMode::enabled()) {
            $this->error('Kein Demo-Modus: basalto:demo-reset läuft nur mit BASALTO_DEMO=true — und löscht dann die ganze Datenbank.');

            return self::FAILURE;
        }
        $database = sprintf('%s «%s»', DB::connection()->getDriverName(), DB::connection()->getDatabaseName());
        if (! $this->option('force') && ! $this->confirm(sprintf('Alle Daten in %s werden gelöscht und die Demo neu aufgesetzt. Weiter?', $database), false)) {
            $this->warn('Abgebrochen — nichts geändert.');

            return self::FAILURE;
        }
        try {
            $result = $reset->run();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Demo neu aufgesetzt: Site «%s» · %d Benutzer · %d Inhalte · %d Fassungen.', $result['site'], $result['counts']['users'], $result['counts']['entries'], $result['counts']['entry_versions']));
        $this->line('Prüfsumme '.$result['fingerprint']);

        return self::SUCCESS;
    }
}
