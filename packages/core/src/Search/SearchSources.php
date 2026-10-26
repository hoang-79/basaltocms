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
 * `basalto:search-index`. Beim Booten gefüllt (Singleton), danach nur gelesen.
 */
final class SearchSources
{
    public const ENTRY = 'entry';

    /** @var array<string, array{label: string, rebuild: Closure(Site): void}> */
    private array $sources = [];

    /** @param  Closure(Site): void  $rebuild */
    public function register(string $source, string $label, Closure $rebuild): self
    {
        $this->sources[$source] = ['label' => $label, 'rebuild' => $rebuild];

        return $this;
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
