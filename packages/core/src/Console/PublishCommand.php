<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Models\Site;
use Basalto\Core\Publish\PublishChecks;
use Basalto\Core\Publish\Publisher;
use Illuminate\Console\Command;

/**
 * `basalto:publish` (L-06): Seed einspielen, Links, Bilder, Alt-Texte, Pflichtsprachen, Rechtsseiten, Formulare und
 * Umleitungen prüfen, vorwärmen, live schalten — oder mit Grund ablehnen. Dieselbe Prüfung zeigt die Einrichtung.
 */
final class PublishCommand extends Command
{
    protected $signature = 'basalto:publish {site : Handle der Site} {--dry-run : Nur prüfen, nichts schreiben} {--ohne-seed : Seed nicht einspielen}';

    protected $description = 'Site prüfen und live schalten';

    public function handle(Publisher $publisher): int
    {
        $site = Site::query()->where('handle', (string) $this->argument('site'))->first();
        if ($site === null) {
            $this->error(sprintf('Site «%s» gibt es nicht.', (string) $this->argument('site')));

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $result = $publisher->run($site, $dry, ! $this->option('ohne-seed'));
        $this->line(sprintf('basalto:publish %s%s', $site->handle, $dry ? ' (Probelauf)' : ''));
        if ($result['seed'] !== null) {
            $this->line(sprintf('  %-14s ✓ %d angelegt · %d vorhanden', 'Seed', count($result['seed']['created']), count($result['seed']['kept'])));
        }
        foreach ($result['checks'] as $check) {
            $area = PublishChecks::AREAS[$check] ?? $check;
            $found = array_filter($result['findings'], static fn (array $f): bool => $f['area'] === $area);
            $errors = array_filter($found, static fn (array $f): bool => $f['severity'] === 'error');
            $this->line(sprintf('  %-14s %s %s', $area, $errors === [] ? '✓' : '✗', $errors === [] ? sprintf('%d geprüft', $result['counted'][$check] ?? 0) : sprintf('%d Fehler', count($errors))));
        }
        foreach ($result['findings'] as $finding) {
            $this->line(sprintf('  %s %s: %s', $finding['severity'] === 'error' ? '✗' : '!', $finding['area'], $finding['message']));
        }
        if ($result['errors'] > 0) {
            $this->error(sprintf('Nicht live geschaltet — %d Fehler.', $result['errors']));

            return self::FAILURE;
        }
        if ($dry) {
            $this->info('Probelauf grün — bereit für basalto:publish.');
        } else {
            $this->info(sprintf('%s — %s.', $result['was_live'] ? 'Veröffentlicht' : 'Live geschaltet', app(PageCache::class)->enabled($site) ? sprintf('%d Seite(n) vorgewärmt', $result['warmed']) : 'Seiten-Cache ist aus, nichts vorgewärmt'));
        }

        return self::SUCCESS;
    }
}
