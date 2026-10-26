<?php

declare(strict_types=1);

namespace Basalto\Core\Search;

use Basalto\Core\Models\SearchDocument;
use Basalto\Core\Models\Site;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Suche über Module (CR-006 FO-K12): Neben den Seiten (`entry`) liefern Module eigene Dokumente in denselben Index —
 * das Forum seine Themen. Jede Quelle hat eine Bezeichnung für den Filter der Suche und einen Neuaufbau für
 * `basalto:search-index`. Beim Booten gefüllt (Singleton), danach nur gelesen. Seit TB 71 kann eine Quelle ihre
 * Treffer verfeinern: Das Forum zeigt statt des Themas den passenden Beitrag (Link, Ausschnitt, wer und wann).
 *
 * @phpstan-type Hit array{path?: string, excerpt?: string, meta?: string}
 */
final class SearchSources
{
    public const ENTRY = 'entry';

    /** @var array<string, array{label: string, rebuild: Closure(Site): void, hits: (Closure(Site, list<int>, string, string): array<int, Hit>)|null}> */
    private array $sources = [];

    /**
     * @param  Closure(Site): void  $rebuild
     * @param  (Closure(Site, list<int>, string, string): array<int, Hit>)|null  $hits  Kennungen der Quelle, Abfrage, Sprache → verfeinerte Treffer
     */
    public function register(string $source, string $label, Closure $rebuild, ?Closure $hits = null): self
    {
        $this->sources[$source] = ['label' => $label, 'rebuild' => $rebuild, 'hits' => $hits];

        return $this;
    }

    /**
     * Treffer einer Quelle verfeinern — leer, wenn die Quelle das nicht kann.
     *
     * @param  list<int>  $ids
     * @return array<int, Hit>
     */
    public function hits(Site $site, string $source, array $ids, string $query, string $locale): array
    {
        $hits = $this->sources[$source]['hits'] ?? null;

        return $hits === null || $ids === [] ? [] : $hits($site, $ids, $query, $locale);
    }

    /** @return array<string, string> Quelle → Bezeichnung (Seiten zuerst) */
    public function labels(?string $lang = null): array
    {
        $out = [self::ENTRY => (string) __('Seiten', [], $lang)];
        foreach ($this->sources as $key => $source) {
            $out[$key] = (string) __($source['label'], [], $lang);
        }

        return $out;
    }

    public function rebuild(Site $site): void
    {
        foreach ($this->sources as $source) {
            ($source['rebuild'])($site);
        }
    }

    /** Ein Dokument einer Quelle anlegen oder ändern. */
    public function put(Site $site, string $source, int $id, string $locale, string $type, string $title, string $body, string $path, ?DateTimeInterface $published = null): void
    {
        SearchDocument::query()->updateOrCreate(['source' => $source, 'source_id' => $id], ['site_id' => $site->id, 'entry_id' => null, 'locale' => $locale, 'type' => $type,
            'title' => Str::limit($title, 250, ''), 'body' => Str::limit($body, SearchIndexer::BODY_LIMIT, ''), 'path' => $path, 'published_at' => $published]);
    }

    public function remove(string $source, int $id): void
    {
        SearchDocument::query()->where('source', $source)->where('source_id', $id)->get()->each->delete();
    }
}
