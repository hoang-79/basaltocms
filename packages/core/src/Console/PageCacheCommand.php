<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Jobs\WarmPageCache;
use Basalto\Core\Models\Site;
use Illuminate\Console\Command;

/** Seiten-Cache von Hand (M-16): `warm` rendert alle veröffentlichten Seiten vor, `clear` lässt alle veralten. */
final class PageCacheCommand extends Command
{
    protected $signature = 'basalto:page-cache {aktion : warm oder clear} {--site= : Handle einer Site (sonst alle)}';

    protected $description = 'Seiten-Cache vorwärmen oder leeren';

    public function handle(PageCache $cache): int
    {
        $action = (string) $this->argument('aktion');
        if (! in_array($action, ['warm', 'clear'], true)) {
            $this->error('Aktion «warm» oder «clear».');

            return self::INVALID;
        }
        $handle = $this->option('site');
        $sites = Site::query()->when(is_string($handle) && $handle !== '', static fn ($q) => $q->where('handle', $handle))->get();
        if ($sites->isEmpty()) {
            $this->error('Keine Site gefunden.');

            return self::FAILURE;
        }
        foreach ($sites as $site) {
            if ($action === 'clear') {
                $cache->invalidate($site->id);
                $this->info(sprintf('%s: Seiten-Cache geleert.', $site->handle));

                continue;
            }
            $warmed = app()->call([new WarmPageCache($site->id), 'handle']);
            $this->info(sprintf('%s: %d Seite(n) vorgewärmt%s.', $site->handle, $warmed, $cache->enabled($site) ? '' : ' (Seiten-Cache ist aus)'));
        }

        return self::SUCCESS;
    }
}
