<?php

declare(strict_types=1);

namespace Basalto\Core\Search;

use Basalto\Core\Models\SearchDocument;
use Basalto\Core\Models\Site;
use Illuminate\Http\Request;

/**
 * Die Suche im Frontend (M-12): `{% search %}` setzt ein Suchformular mit Treffern dieser Site und Sprache — Titel,
 * Ausschnitt mit markiertem Begriff, Seiten. Die Ergebnisseite trägt eine Abfrage (`?q=`) und geht darum nie in den
 * Seiten-Cache. Der Treiber (Datenbank, später Meilisearch) kommt aus `config/scout.php`. Seit TB 41 mischt sie die
 * Quellen der Module (Forum) dazu und filtert mit `?bereich=`.
 */
final class SiteSearch
{
    public const PER_PAGE = 10;

    /** @return array{items: list<array{title: string, path: string, excerpt: string}>, total: int, page: int, pages: int} */
    public function search(Site $site, string $locale, string $query, int $page = 1, ?string $source = null): array
    {
        $query = trim(mb_substr($query, 0, 100));
        if (mb_strlen($query) < 2) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
        }
        $search = SearchDocument::search($query)->where('site_id', $site->id)->where('locale', $locale);
        // TB 41 (FO-K12): nur eine Quelle (Seiten, Forum …)
        if ($source !== null) {
            $search->where('source', $source);
        }
        $results = $search->paginate(self::PER_PAGE, 'seite', max(1, $page));
        $items = [];
        foreach ($results->items() as $doc) {
            /** @var SearchDocument $doc */
            $items[] = ['title' => $doc->title, 'path' => $doc->path, 'excerpt' => self::excerpt($doc->body, $query)];
        }

        return ['items' => $items, 'total' => $results->total(), 'page' => $results->currentPage(), 'pages' => $results->lastPage()];
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
                $html .= sprintf('<li><a href="%s">%s</a><p>%s</p></li>', $e($item['path']), $e($item['title']), $item['excerpt']);
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

    /** Ausschnitt um den ersten Treffer, escaped, der Begriff mit `<mark>`. */
    public static function excerpt(string $body, string $query): string
    {
        $pos = mb_stripos($body, $query);
        $start = $pos === false ? 0 : max(0, $pos - 70);
        $text = ($start > 0 ? '…' : '').mb_substr($body, $start, 200).(mb_strlen($body) > $start + 200 ? '…' : '');
        $safe = htmlspecialchars($text, ENT_QUOTES);

        return (string) preg_replace('/('.preg_quote(htmlspecialchars($query, ENT_QUOTES), '/').')/iu', '<mark>$1</mark>', $safe);
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
