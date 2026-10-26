<?php

declare(strict_types=1);

namespace Basalto\Core\Search;

use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\RichText;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\SearchDocument;
use Basalto\Core\Models\Site;
use Illuminate\Support\Str;

/**
 * Hält die Suchdokumente einer Site aktuell (M-12): je Inhalt und Sprache die veröffentlichte eigene Fassung — Titel,
 * Text aus Einleitung, Feldern und Blöcken (Fliesstext als Text), Pfad. Nicht im Index: Weiterleitungen, Feeds,
 * Ordner, Fehlerseiten, Papierkorb, «noindex», geschützte Seiten (nur für Mitglieder) und Inhalte, die im Reiter
 * Experten aus der Suche genommen sind.
 */
final class SearchIndexer
{
    public const BODY_LIMIT = 20_000;

    private const SKIP_KEYS = ['href', 'src', 'media_id', 'type', 'id', 'focus_x', 'focus_y', 'slug', 'settings', 'form', 'columns', 'width', 'variant', 'title', 'date', 'author', 'per'];

    public function __construct(private readonly Pages $pages) {}

    public function index(Entry $entry): void
    {
        $site = $entry->site;
        foreach ($site->localeCodes() as $locale) {
            $version = $this->listed($entry) ? $this->pages->versionOf($entry, $locale) : null;
            $path = $version === null ? null : $this->pages->ownPathOf($entry, $locale);
            $query = SearchDocument::query()->where('entry_id', $entry->id)->where('locale', $locale);
            if ($version === null || $path === null) {
                $query->delete();

                continue;
            }
            $full = EntryVersion::query()->find($version->id) ?? $version;
            SearchDocument::query()->updateOrCreate(['entry_id' => $entry->id, 'locale' => $locale], ['source' => SearchSources::ENTRY,
                'site_id' => $site->id, 'type' => $entry->type, 'title' => Str::limit($full->title, 250, ''),
                'body' => Str::limit(self::text($full->data), self::BODY_LIMIT, ''), 'path' => $this->pages->urlPath($site, $locale, $path),
                'published_at' => $full->published_at,
            ]);
        }
    }

    /** @return int Zahl der Dokumente nach dem Neuaufbau */
    public function rebuild(Site $site): int
    {
        SearchDocument::query()->where('site_id', $site->id)->delete();
        $this->pages->forget();
        foreach ($this->pages->entries($site) as $entry) {
            $this->index($entry);
        }
        // TB 41 (FO-K12): und die Quellen der Module
        app(SearchSources::class)->rebuild($site);

        return SearchDocument::query()->where('site_id', $site->id)->count();
    }

    private function listed(Entry $entry): bool
    {
        $type = $entry->pageType();
        if ($entry->trashed() || ($type !== null && ! in_array($type, [PageType::Page], true))) {
            return false;
        }
        // TB 13 Scheibe 2: geschützte Seiten nie im Suchindex — die Suche ist öffentlich, auch ihre Ausschnitte
        if ($entry->setting('expert.in_search', true) === false || str_contains((string) $entry->setting('meta.robots', ''), 'noindex') || $this->pages->isProtected($entry)) {
            return false;
        }

        return $entry->versions()->where('status', EntryStatus::Published->value)->exists();
    }

    /** Aller Text einer Fassung — Fliesstext als Text, Links und Ids weg. */
    public static function text(mixed $data): string
    {
        $parts = [];
        self::collect($data, $parts);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** @param  list<string>  $parts */
    private static function collect(mixed $value, array &$parts, ?string $key = null): void
    {
        if ($key !== null && in_array($key, self::SKIP_KEYS, true)) {
            return;
        }
        if (is_array($value) && ($value['type'] ?? null) === 'doc') {
            $parts[] = RichText::toText($value);

            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::collect($v, $parts, is_string($k) ? $k : null);
            }

            return;
        }
        if (is_string($value) && trim($value) !== '' && ! preg_match('#^(https?:)?/#', $value)) {
            $parts[] = strip_tags($value);
        }
    }
}
