<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Backup\Backups;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Notifications\Recipients;
use Basalto\Core\Transfer\TransferException;
use Illuminate\Console\Command;

/**
 * Sicherung von Datenbank und Medien (K-17, B-10) — täglich 03:15 aus dem Scheduler, «Backup jetzt» auf der Seite
 * Betrieb, von Hand vor jedem Update. Ältere Sicherungen über der Aufbewahrung (`backup.keep`) werden gelöscht.
 */
final class BackupCommand extends Command
{
    protected $signature = 'basalto:backup {--path= : Ordner der Sicherungen (sonst storage/app/backups)}';

    protected $description = 'Datenbank und Medien sichern (mit Manifest und Prüfsummen)';

    public function handle(Backups $backups): int
    {
        $path = $this->option('path');
        try {
            $result = $backups->create(is_string($path) ? $path : null);
        } catch (TransferException $e) {
            $this->error('Sicherung gescheitert: '.$e->getMessage());
            // TB 27: wer den Betrieb verantwortet, erfährt es ohne Blick in die Protokolle
            Notify::make('operations.backup_failed')->title('Die Sicherung ist gescheitert')->body('Grund: :reason', ['reason' => $e->getMessage()])
                ->url(route('basalto.operations'), 'Betrieb öffnen')->to(Recipients::installation('operations'))->send();

            return self::FAILURE;
        }
        $this->info(sprintf('Sicherung geschrieben: %s (%s, %d Dateien, Datenbank %s; %d Sites, %d Inhalte, %d Medien).', $result['path'], ExportCommand::bytes($result['size']),
            $result['files'], $result['driver'], $result['counts']['sites'], $result['counts']['entries'], $result['counts']['media']));
        if ($result['removed'] !== []) {
            $this->line(sprintf('Aufbewahrung: %d ältere Sicherung(en) gelöscht.', count($result['removed'])));
        }

        return self::SUCCESS;
    }
}
