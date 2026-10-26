<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Models\Site;
use Basalto\Core\Publish\LegalPages;
use Basalto\Core\Publish\LegalTemplates;
use Illuminate\Console\Command;

/** Rechtsseite aus Vorlage (M-14): Impressum oder Datenschutz als Entwurf je Sprache, Bekanntes eingesetzt. */
final class LegalCommand extends Command
{
    protected $signature = 'basalto:legal {site : Handle der Site} {art : impressum oder datenschutz}';

    protected $description = 'Impressum oder Datenschutzerklärung aus Vorlage als Entwurf anlegen';

    public function handle(LegalPages $legal): int
    {
        $site = Site::query()->where('handle', (string) $this->argument('site'))->first();
        $kind = (string) $this->argument('art');
        if ($site === null || ! in_array($kind, LegalTemplates::KINDS, true)) {
            $this->error('Site oder Art unbekannt (impressum, datenschutz).');

            return self::FAILURE;
        }
        $entry = $legal->apply($site, $kind);
        $open = [];
        foreach ($site->localeCodes() as $locale) {
            preg_match_all('/\[\[([^\]]+)\]\]/u', (string) json_encode($entry->latestVersion($locale)?->data, JSON_UNESCAPED_UNICODE), $m);
            $open = array_merge($open, $m[1]);
        }
        $open = array_values(array_unique($open));
        $this->info(sprintf('%s als Entwurf in %d Sprache(n) angelegt.', LegalTemplates::meta($kind, $site->default_locale)['title'], count($site->localeCodes())));
        if ($open !== []) {
            $this->line('Noch auszufüllen: '.implode(', ', array_map(static fn (string $p): string => '[['.$p.']]', $open)));
        }

        return self::SUCCESS;
    }
}
