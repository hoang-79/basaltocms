<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Backup\Backups;
use Basalto\Core\Backup\BackupVerifier;
use Illuminate\Console\Command;

/**
 * Wiederherstellungsprüfung einer Sicherung (K-17, B-10): Prüfsummen, bei SQLite Rückspielen mit Integritäts- und
 * Zählprobe, sonst Vollständigkeit des Abzugs. Ohne Angabe die neueste Sicherung im Standardordner.
 */
final class BackupVerifyCommand extends Command
{
    protected $signature = 'basalto:backup-verify {archiv? : Pfad zur Sicherung (.zip; sonst die neueste)} {--path= : Ordner der Sicherungen (sonst storage/app/backups)}';

    protected $description = 'Sicherung prüfen (Prüfsummen, Rückspielprobe)';

    public function handle(BackupVerifier $verifier): int
    {
        $archive = $this->argument('archiv');
        $path = $this->option('path');
        if (! is_string($archive) || $archive === '') {
            $archive = Backups::list(is_string($path) ? $path : null)[0] ?? null;
            if ($archive === null) {
                $this->error('Keine Sicherung gefunden — zuerst basalto:backup ausführen.');

                return self::FAILURE;
            }
        }
        $result = $verifier->verify($archive);
        foreach ($result['checks'] as $check) {
            $result['ok'] ? $this->line('✓ '.$check) : $this->line('· '.$check);
        }
        if (! $result['ok']) {
            $this->error(sprintf('Prüfung gescheitert: %s', basename($archive)));

            return self::FAILURE;
        }
        $this->info(sprintf('Wiederherstellung geprüft: %s (%s).', basename($archive), $result['driver']));

        return self::SUCCESS;
    }
}
