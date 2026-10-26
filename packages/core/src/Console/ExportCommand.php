<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Models\Site;
use Basalto\Core\Transfer\SiteExporter;
use Basalto\Core\Transfer\TransferException;
use Illuminate\Console\Command;

/**
 * Vollexport einer Site (M-18) als ZIP: `site.json` mit allen Daten, Mediendateien, Manifest mit Prüfsummen.
 * Formulareingänge nur mit `--with-submissions` — sie sind Personendaten (N-05).
 */
final class ExportCommand extends Command
{
    protected $signature = 'basalto:export {site : Handle der Site} {--path= : Ziel (Datei .zip oder Ordner; sonst storage/app/exports)} {--with-submissions : Formulareingänge samt Dateien mitnehmen (Personendaten)}';

    protected $description = 'Site vollständig exportieren (JSON + Medien)';

    public function handle(SiteExporter $exporter, Recorder $recorder): int
    {
        $site = Site::query()->where('handle', (string) $this->argument('site'))->first();
        if ($site === null) {
            $this->error(sprintf('Site «%s» gibt es nicht.', (string) $this->argument('site')));

            return self::FAILURE;
        }
        $path = self::target($this->option('path'), $site);
        try {
            $result = $exporter->export($site, $path, (bool) $this->option('with-submissions'));
        } catch (TransferException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $recorder->record('site.exported', $site, null, ['file' => basename($result['path']), 'size' => $result['size'], 'with_submissions' => (bool) $this->option('with-submissions')], $site);
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }
        $c = $result['counts'];
        $this->info(sprintf('Export von «%s»: %d Inhalte mit %d Fassungen, %d Medien, %d Umleitungen, %d Menüeinträge, %d Formulare, %d Eingänge — %d Dateien, %s.',
            $site->handle, $c['entries'], $c['versions'], $c['media'], $c['redirects'], $c['navigation'], $c['forms'], $c['submissions'], $result['files'], self::bytes($result['size'])));
        $this->line($result['path']);

        return self::SUCCESS;
    }

    /** Datei (endet auf .zip) oder Ordner; ohne Angabe storage/app/exports mit Handle und Zeitstempel. */
    public static function target(mixed $option, Site $site): string
    {
        $name = sprintf('basalto-site-%s-%s.zip', $site->handle, now()->format('Ymd-His'));
        if (! is_string($option) || $option === '') {
            return storage_path('app/exports/'.$name);
        }

        return str_ends_with(strtolower($option), '.zip') ? $option : rtrim($option, '/').'/'.$name;
    }

    public static function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', "'").' MB' : number_format($bytes / 1024, 1, ',', "'").' KB';
    }
}
