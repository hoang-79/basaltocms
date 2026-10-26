<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Console;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Models\Site;
use Basalto\Modules\Forum\Counters;
use Illuminate\Console\Command;

/**
 * Zähler des Forums neu rechnen (CR-006, Risiko «Zähler laufen auseinander»): Themen, Beiträge und letzter Beitrag je
 * Forum, Antworten je Thema, Beitragszahl je Profil — für eine Site oder alle mit eingeschaltetem Forum.
 */
final class ForumRecountCommand extends Command
{
    protected $signature = 'basalto:forum-recount {site? : Kennung der Site (ohne: alle mit Forum)}';

    protected $description = 'Rechnet die Zähler des Forums neu (Foren, Themen, Profile)';

    public function handle(Counters $counters): int
    {
        $handle = $this->argument('site');
        $sites = Site::query()->when(is_string($handle) && $handle !== '', static fn ($q) => $q->where('handle', $handle))->get()
            ->filter(static fn (Site $site): bool => app(Backend::class)->moduleEnabled('forum', $site));
        foreach ($sites as $site) {
            $counters->all($site);
            $this->line(sprintf('%s: Zähler neu gerechnet.', $site->handle));
        }
        if ($sites->isEmpty()) {
            $this->warn('Keine Site mit eingeschaltetem Forum gefunden.');
        }

        return self::SUCCESS;
    }
}
