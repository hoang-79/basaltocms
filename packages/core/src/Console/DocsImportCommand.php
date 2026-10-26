<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Content\DocsImporter;
use Basalto\Core\Models\Site;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `basalto:docs-import` (TB 11 Scheibe 4): die Markdown-Doku aus `docs/` als Seiten eines Typs anlegen bzw. auffrischen
 * — für basaltocms.com (Typ «doc» im Theme «basalto»). Übersetzungen aus `docs/<sprache>/`, Rechtstexte ausgenommen.
 */
final class DocsImportCommand extends Command
{
    protected $signature = 'basalto:docs-import {site : Handle der Site} {--path=docs : Ordner mit der Markdown-Doku, relativ zum Projekt} {--type=doc : Inhaltstyp des Themes}';

    protected $description = 'Markdown-Doku (docs/) als Seiten einer Site anlegen oder auffrischen';

    public function handle(DocsImporter $importer): int
    {
        $site = Site::query()->where('handle', (string) $this->argument('site'))->first();
        if ($site === null) {
            $this->error(sprintf('Site «%s» gibt es nicht.', (string) $this->argument('site')));

            return self::FAILURE;
        }
        $path = (string) $this->option('path');
        try {
            $result = $importer->import($site, str_starts_with($path, '/') ? $path : base_path($path), (string) $this->option('type'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ([...$result['created'], ...$result['updated']] as $line) {
            $this->line('  ✓ '.$line);
        }
        foreach ($result['removed'] as $line) {
            $this->line('  − '.$line.' (in den Papierkorb)');
        }
        $this->info(sprintf('Doku: %d neu · %d aufgefrischt · %d unverändert', count($result['created']), count($result['updated']), count($result['kept']))
            .($result['removed'] !== [] ? sprintf(' · %d entfernt.', count($result['removed'])) : '.'));

        return self::SUCCESS;
    }
}
