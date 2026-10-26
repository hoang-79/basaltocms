<?php

declare(strict_types=1);

namespace Basalto\Core\Search;

use Basalto\Core\Models\SearchDocument;
use Basalto\Core\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Scout\Builder as ScoutBuilder;

/**
 * Die Suche im Frontend (M-12): `{% search %}` setzt ein Suchformular mit Treffern dieser Site und Sprache — Titel,
 * Ausschnitt mit markiertem Begriff, Seiten. Die Ergebnisseite trägt eine Abfrage (`?q=`) und geht darum nie in den
 * Seiten-Cache. Der Treiber (Datenbank, später Meilisearch) kommt aus `config/scout.php`. Seit TB 41 mischt sie die
 * Quellen der Module (Forum) dazu und filtert mit `?bereich=`. Seit TB 71 sucht sie mit dem Datenbank-Treiber nach
 * Wörtern statt nach der ganzen Eingabe ({@see SearchText}).
 */
final class SiteSearch
{
    public const PER_PAGE = 10;

    /** Treiber, bei denen die Suche selbst in der Datenbank sucht (nach Wörtern, gefaltet) statt über Scout. */
    private const OWN_DRIVERS = ['database', 'collection'];

    /** @return array{items: list<array{title: string, path: string, excerpt: string, meta: string}>, total: int, page: int, pages: int} */
    public function search(Site $site, string $locale, string $query, int $page = 1, ?string $source = null): array
    {
        $query = trim(mb_substr($query, 0, 100));
        $words = SearchText::words($query);
        if (mb_strlen($query) < 2 || $words === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
        }
        $results = in_array(config('scout.driver'), self::OWN_DRIVERS, true)
            ? $this->byWords($site, $locale, $words, $source)->paginate(self::PER_PAGE, ['id', 'source', 'source_id', 'title', 'body', 'path'], 'seite', max(1, $page))
            : $this->byScout($site, $locale, $query, $source)->paginate(self::PER_PAGE, 'seite', max(1, $page));
        /** @var list<SearchDocument> $docs */
        $docs = $results->items();
        // TB 71: Quellen verfeinern ihre Treffer (das Forum zeigt den passenden Beitrag)
        $hits = [];
        foreach (collect($docs)->whereNotNull('source_id')->groupBy('source') as $from => $group) {
            $hits[$from] = app(SearchSources::class)->hits($site, (string) $from, array_values(array_map('intval', $group->pluck('source_id')->all())), $query, $locale);
        }
        $items = [];
        foreach ($docs as $doc) {
            $hit = $hits[$doc->source][(int) $doc->source_id] ?? [];
            $items[] = ['title' => $doc->title, 'path' => $hit['path'] ?? $doc->path, 'excerpt' => $hit['excerpt'] ?? self::excerpt($doc->body, $query), 'meta' => $hit['meta'] ?? ''];
        }

        return ['items' => $items, 'total' => $results->total(), 'page' => $results->currentPage(), 'pages' => $results->lastPage()];
    }

    /**
     * TB 71: Jedes Wort muss in Titel oder Text stehen, in beliebiger Reihenfolge, ohne Unterschied von Grösse und
     * Akzent. Vorne steht, was die ganze Wortfolge im Titel, dann im Text trägt, dann je mehr Wörter im Titel, sonst
     * das Neuste. Die Wörter bestehen nur aus Buchstaben und Ziffern — `%` und `_` kommen in `LIKE` darum nie vor.
     *
     * @param  list<string>  $words
     * @return Builder<SearchDocument>
     */
    private function byWords(Site $site, string $locale, array $words, ?string $source): Builder
    {
        $search = SearchDocument::query()->where('site_id', $site->id)->where('locale', $locale);
        // TB 41 (FO-K12): nur eine Quelle (Seiten, Forum …)
        if ($source !== null) {
            $search->where('source', $source);
        }
        foreach ($words as $word) {
            $search->where(fn (Builder $q) => $q->where('search_title', 'like', '%'.$word.'%')->orWhere('search_body', 'like', '%'.$word.'%'));
        }
        $phrase = '%'.implode(' ', $words).'%';
        $score = 'CASE WHEN search_title LIKE ? THEN 4 ELSE 0 END + CASE WHEN search_body LIKE ? THEN 2 ELSE 0 END'
            .str_repeat(' + CASE WHEN search_title LIKE ? THEN 1 ELSE 0 END', count($words));

        return $search->orderByRaw('('.$score.') DESC', [$phrase, $phrase, ...array_map(static fn (string $w): string => '%'.$w.'%', $words)])->orderByDesc('id');
    }

    /**
     * Externe Treiber (Meilisearch) suchen selbst nach Wörtern und falten selbst.
     *
     * @return ScoutBuilder<SearchDocument>
     */
    private function byScout(Site $site, string $locale, string $query, ?string $source): ScoutBuilder
    {
        $search = SearchDocument::search($query)->where('site_id', $site->id)->where('locale', $locale);
        if ($source !== null) {
            $search->where('source', $source);
        }

        return $search;
    }

    public function render(Site $site, string $locale, Request $request): string
    {
        $q = is_string($request->query('q')) ? trim((string) $request->query('q')) : '';
        $page = (int) $request->query('seite', 1);
        $sources = app(SearchSources::class)->labels($locale);
        $source = is_string($request->query('bereich')) && array_key_exists($request->query('bereich'), $sources) ? (string) $request->query('bereich') : null;
        $result = $this->search($site, $locale, $q, $page, $source);
        $t = self::texts($locale);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
        $path = $request->getPathInfo();
        $html = sprintf('<div class="search"><form role="search" method="get" action="%1$s"><label for="suche-q">%2$s</label><input type="search" id="suche-q" name="q" value="%3$s" minlength="2" maxlength="100"><button type="submit">%2$s</button></form>',
            $e($path), $e($t['label']), $e($q));
        if ($q === '') {
            return $html.'</div>';
        }
        if (mb_strlen($q) < 2) {
            return $html.sprintf('<p class="search-hint" role="status">%s</p></div>', $e($t['short']));
        }
        // TB 41: Filter nach Quelle, sobald es mehr als Seiten gibt
        if (count($sources) > 1) {
            $links = sprintf('<li><a href="%s?q=%s"%s>%s</a></li>', $e($path), $e(rawurlencode($q)), $source === null ? ' aria-current="true"' : '', $e($t['all']));
            foreach ($sources as $key => $label) {
                $links .= sprintf('<li><a href="%s?q=%s&amp;bereich=%s"%s>%s</a></li>', $e($path), $e(rawurlencode($q)), $e($key), $source === $key ? ' aria-current="true"' : '', $e($label));
            }
            $html .= '<nav class="search-filter" aria-label="'.$e($t['filter']).'"><ul>'.$links.'</ul></nav>';
        }
        $html .= sprintf('<p class="search-count" role="status">%s</p>', $e(sprintf($result['total'] === 1 ? $t['one'] : $t['many'], $result['total'], $q)));
        if ($result['items'] !== []) {
            $html .= '<ol class="search-results">';
            foreach ($result['items'] as $item) {
                $html .= sprintf('<li><a href="%s">%s</a>%s<p>%s</p></li>', $e($item['path']), $e($item['title']), $item['meta'] === '' ? '' : '<p class="search-meta">'.$e($item['meta']).'</p>', $item['excerpt']);
            }
            $html .= '</ol>';
        }
        if ($result['pages'] > 1) {
            $html .= '<nav class="pagination" aria-label="'.$e($t['pages']).'"><ul>';
            for ($i = 1; $i <= $result['pages']; $i++) {
                $html .= $i === $result['page'] ? sprintf('<li><span aria-current="page">%d</span></li>', $i) : sprintf('<li><a href="%s?q=%s%s&amp;seite=%d">%d</a></li>', $e($path), $e(rawurlencode($q)), $source === null ? '' : '&amp;bereich='.$e($source), $i, $i);
            }
            $html .= '</ul></nav>';
        }

        return $html.'</div>';
    }

    /** Ausschnitt um den ersten Treffer, maskiert, jedes gesuchte Wort mit `<mark>` (TB 71: auch «Übersetzung» für «ubersetzung»). */
    public static function excerpt(string $body, string $query): string
    {
        $words = SearchText::words($query);
        $pos = SearchText::anchor($body, $words);
        $start = $pos === null ? 0 : max(0, $pos - 40);
        $text = mb_substr($body, $start, 200);

        return ($start > 0 ? '…' : '').SearchText::mark($text, $words).(mb_strlen($body) > $start + 200 ? '…' : '');
    }

    /** @return array{label: string, one: string, many: string, pages: string, short: string, all: string, filter: string} */
    private static function texts(string $locale): array
    {
        return match ($locale) {
            'en' => ['label' => 'Search', 'one' => '%d result for «%s»', 'many' => '%d results for «%s»', 'pages' => 'Result pages', 'short' => 'Please enter at least two characters.', 'all' => 'All', 'filter' => 'Filter results'],
            'it' => ['label' => 'Cerca', 'one' => '%d risultato per «%s»', 'many' => '%d risultati per «%s»', 'pages' => 'Pagine dei risultati', 'short' => 'Inserire almeno due caratteri.', 'all' => 'Tutto', 'filter' => 'Filtra i risultati'],
            'fr' => ['label' => 'Rechercher', 'one' => '%d résultat pour « %s »', 'many' => '%d résultats pour « %s »', 'pages' => 'Pages de résultats', 'short' => 'Veuillez saisir au moins deux caractères.', 'all' => 'Tout', 'filter' => 'Filtrer les résultats'],
            default => ['label' => 'Suchen', 'one' => '%d Treffer für «%s»', 'many' => '%d Treffer für «%s»', 'pages' => 'Trefferseiten', 'short' => 'Bitte mindestens zwei Zeichen eingeben.', 'all' => 'Alle', 'filter' => 'Treffer filtern'],
        };
    }
}
