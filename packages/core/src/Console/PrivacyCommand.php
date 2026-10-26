<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Privacy\LastOwnerException;
use Basalto\Core\Privacy\PersonData;
use Illuminate\Console\Command;

/**
 * Auskunft und Löschung für eine Person über alle Sites (N-05): `--export` schreibt alles als JSON, `--delete` löscht
 * Konto und Formulareingänge endgültig und anonymisiert das Protokoll.
 */
final class PrivacyCommand extends Command
{
    protected $signature = 'basalto:privacy {email : E-Mail-Adresse der Person} {--export= : Datei für die Auskunft (JSON)} {--delete : Konto und Eingänge löschen} {--force : Ohne Rückfrage}';

    protected $description = 'Auskunft über eine Person oder ihre Daten löschen (DSG/DSGVO)';

    public function handle(PersonData $people, Recorder $recorder): int
    {
        $email = (string) $this->argument('email');
        $data = $people->export($email);
        $this->info(sprintf('%s: %s, %d Protokolleinträge, %d Formulareingänge.', $email, $data['konto'] === null ? 'kein Konto' : 'Konto vorhanden', count($data['protokoll']), count($data['formulareingaenge'])));
        $file = $this->option('export');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $recorder->record('privacy.exported', null, null, ['email' => $email]);
            $this->line('Auskunft geschrieben: '.$file);
        }
        if (! $this->option('delete')) {
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Konto und Eingänge endgültig löschen? Das lässt sich nicht rückgängig machen.')) {
            return self::FAILURE;
        }
        try {
            $result = $people->delete($email);
        } catch (LastOwnerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $recorder->record('privacy.deleted', null, null, ['konto' => $result['konto'], 'protokoll' => $result['protokoll'], 'eingaenge' => $result['eingaenge']]);
        $this->info(sprintf('Gelöscht: %s, %d Eingänge; %d Protokolleinträge anonymisiert.', $result['konto'] ? 'Konto' : 'kein Konto', $result['eingaenge'], $result['protokoll']));

        return self::SUCCESS;
    }
}
