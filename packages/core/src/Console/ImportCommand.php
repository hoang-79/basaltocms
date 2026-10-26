<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Transfer\SiteImporter;
use Basalto\Core\Transfer\TransferException;
use Illuminate\Console\Command;

/**
 * Import eines Site-Exports (M-18) in diese Installation: prüft Archiv und Prüfsummen, legt die Site in einer
 * Transaktion an, baut den Suchindex neu und leert den Seiten-Cache. Der Handle darf noch nicht vergeben sein.
 */
final class ImportCommand extends Command
{
    protected $signature = 'basalto:import {zip : Pfad zum Export (.zip)} {--handle= : Unter diesem Handle anlegen (sonst der aus dem Export)}';

    protected $description = 'Site aus einem Export anlegen';

    public function handle(SiteImporter $importer): int
    {
        $handle = $this->option('handle');
        try {
            $result = $importer->import((string) $this->argument('zip'), is_string($handle) && $handle !== '' ? $handle : null);
        } catch (TransferException $e) {
            $this->error('Import abgelehnt: '.$e->getMessage());

            return self::FAILURE;
        }
        $c = $result['counts'];
        $this->info(sprintf('Site «%s» importiert: %d Inhalte mit %d Fassungen, %d Medien, %d Umleitungen, %d Menüeinträge, %d Formulare, %d Eingänge, %d Dateien; %d Mitglieder, %d Einladungen; %d Dokumente im Suchindex.',
            $result['site']->handle, $c['entries'], $c['versions'], $c['media'], $c['redirects'], $c['navigation'], $c['forms'], $c['submissions'], $c['files'], $c['members'], $c['invitations'], $c['search_documents']));
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }

        return self::SUCCESS;
    }
}
