<?php

declare(strict_types=1);

namespace Basalto\Core\Search;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;

/**
 * Wann der Suchindex nachzieht (M-12): wenn eine Fassung live geht oder es war (Entwürfe nicht), wenn ein Inhalt seine
 * Einstellungen ändert, in den Papierkorb geht oder zurückkommt. Bei Seiten mit Unterseiten ziehen die Nachkommen mit —
 * ihre Pfade hängen am Slug der Eltern.
 */
final class SearchSync
{
    public static function register(): void
    {
        $versionChange = static function (EntryVersion $version): void {
            $published = EntryStatus::Published->value;
            $original = $version->getOriginal('status');
            $before = $original instanceof EntryStatus ? $original->value : (string) $original;
            if ($version->status->value === $published || $before === $published) {
                $entry = Entry::query()->withoutGlobalScopes()->withTrashed()->find($version->entry_id);
                if ($entry !== null) {
                    self::reindex($entry);
                }
            }
        };
        EntryVersion::saved($versionChange);
        EntryVersion::deleted($versionChange);
        Entry::saved(static fn (Entry $entry) => $entry->wasChanged(['settings', 'parent_id', 'deleted_at']) ? self::reindex($entry) : null);
        Entry::deleted(static fn (Entry $entry) => self::reindex($entry));
        Entry::restored(static fn (Entry $entry) => self::reindex($entry));
    }

    private static function reindex(Entry $entry): void
    {
        app(Pages::class)->forget();
        $indexer = app(SearchIndexer::class);
        $queue = [$entry];
        $seen = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current->id])) {
                continue;
            }
            $seen[$current->id] = true;
            $indexer->index($current);
            foreach (Entry::query()->withoutGlobalScopes()->where('parent_id', $current->id)->get() as $child) {
                $queue[] = $child;
            }
        }
    }
}
