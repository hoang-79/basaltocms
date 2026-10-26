<?php

declare(strict_types=1);

namespace Basalto\Core\Console;

use Basalto\Core\Models\Site;
use Basalto\Core\Search\SearchIndexer;
use Illuminate\Console\Command;

/** Suchindex neu aufbauen (M-12) — nach einem Import oder Treiberwechsel. */
final class SearchIndexCommand extends Command
{
    protected $signature = 'basalto:search-index {--site= : Handle einer Site (sonst alle)}';

    protected $description = 'Suchindex der Sites neu aufbauen';

    public function handle(SearchIndexer $indexer): int
    {
        $handle = $this->option('site');
        foreach (Site::query()->when(is_string($handle) && $handle !== '', static fn ($q) => $q->where('handle', $handle))->get() as $site) {
            $this->info(sprintf('%s: %d Dokument(e) im Index.', $site->handle, $indexer->rebuild($site)));
        }

        return self::SUCCESS;
    }
}
