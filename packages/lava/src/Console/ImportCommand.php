<?php

declare(strict_types=1);

namespace Basalto\Lava\Console;

use Basalto\Lava\Theme\DatabaseSource;
use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\LavaSource;
use Basalto\Lava\Theme\ThemeImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** «basalto:lava-import» kopiert ein Datei-Theme als Datensätze in die Datenbank (R-97). */
final class ImportCommand extends Command
{
    protected $signature = 'basalto:lava-import {site : Handle der Site} {--theme=themes/nguyen-development : Theme-Verzeichnis} {--scope=theme : Geltungsbereich (theme oder site)}';

    protected $description = 'Importiert ein Lava-Theme aus Dateien in die Tabelle lava_sources';

    public function handle(): int
    {
        $handle = (string) $this->argument('site');
        $siteId = self::siteIdFor($handle);
        if ($siteId === null) {
            $this->error(sprintf('Site «%s» gibt es nicht.', $handle));

            return self::FAILURE;
        }
        $scope = (string) $this->option('scope');
        if (! in_array($scope, [LavaSource::SCOPE_THEME, LavaSource::SCOPE_SITE], true)) {
            $this->error(sprintf('Geltungsbereich «%s» ist unbekannt — erlaubt sind «theme» und «site».', $scope));

            return self::FAILURE;
        }
        $source = new FileSource(base_path((string) $this->option('theme')));
        $n = $scope === LavaSource::SCOPE_THEME && app()->bound(ThemeImporter::class)
            ? app(ThemeImporter::class)->importTheme($source, $siteId)
            : DatabaseSource::importFrom($source, $siteId, $scope);
        $this->info(sprintf('%d Dateien für Site «%s» (%s) in die Datenbank übernommen.', $n, $handle, $scope));

        return self::SUCCESS;
    }

    /** Das Lava-Paket kennt das Site-Modell nicht — das Handle wird über die Tabelle aufgelöst. */
    public static function siteIdFor(string $handle): ?int
    {
        $id = DB::table('sites')->where('handle', $handle)->value('id');

        return $id === null ? null : (int) $id;
    }
}
