<?php

declare(strict_types=1);

namespace Basalto\Lava\Console;

use Basalto\Lava\Runtime\Registry;
use Basalto\Lava\Theme\Compiler;
use Basalto\Lava\Theme\DatabaseSource;
use Basalto\Lava\Theme\FileSource;
use Basalto\Lava\Theme\Line;
use Basalto\Lava\Theme\SiteSourceResolver;
use Illuminate\Console\Command;

/** «basalto:check» zeigt die rote Linie A–Z mit Haken und alle Befunde mit Zeile — aus Datei oder Datenbank. */
final class CheckCommand extends Command
{
    protected $signature = 'basalto:check {--theme=themes/nguyen-development : Theme-Verzeichnis} {--site= : Handle der Site — Theme aus der Datenbank (lava_sources) statt aus Dateien} {--json : Befunde als JSON (Sprachdienst)}';

    protected $description = 'Prüft ein Lava-Theme und zeigt die rote Linie A–Z';

    public function handle(Registry $registry): int
    {
        $handle = $this->option('site');
        $kind = 'Dateien';
        if ($handle) {
            $resolved = app()->bound(SiteSourceResolver::class) ? app(SiteSourceResolver::class)->forSite((string) $handle) : null;
            $siteId = ImportCommand::siteIdFor((string) $handle);
            if ($siteId === null || (app()->bound(SiteSourceResolver::class) && $resolved === null)) {
                $this->error(sprintf('Site «%s» gibt es nicht.', (string) $handle));

                return self::FAILURE;
            }
            $source = $resolved['source'] ?? new DatabaseSource($siteId, label: (string) $handle);
            $kind = ($resolved['kind'] ?? 'database') === 'database' ? 'Datenbank' : 'Dateien';
        } else {
            $source = new FileSource(base_path((string) $this->option('theme')));
        }
        $compiler = new Compiler($registry);
        $theme = $compiler->compile($source);
        $line = new Line($theme, $compiler->diagnostics);
        if ($this->option('json')) {
            $this->line((string) json_encode(['line' => $line->rows(), 'diagnostics' => array_map(static fn ($d): array => $d->toArray(), $compiler->diagnostics)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $compiler->diagnostics === [] || ! array_filter($compiler->diagnostics, static fn ($d): bool => $d->isError()) ? self::SUCCESS : self::FAILURE;
        }
        $this->line(sprintf('Theme «%s» (%s)', $source->name(), $kind));
        // Direkt auf den Symfony-Output: Laravels OutputStyle fasst Mehrfach-Leerzeichen zusammen und zerstört die Spalten.
        $this->output->getOutput()->write($line->render());
        $errors = 0;
        foreach ($compiler->diagnostics as $d) {
            $errors += $d->isError() ? 1 : 0;
            $this->line(($d->isError() ? '  ✗ ' : '  ! ').$d->format());
        }
        if ($errors === 0 && $line->isComplete()) {
            $this->info('Rote Linie vollständig — bereit für publish.');
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
