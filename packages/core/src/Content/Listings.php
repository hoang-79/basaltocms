<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Listen von Inhalten im Theme (M-13, Blog): `{% each entries("post", 10) as post %}` liefert die veröffentlichten
 * Inhalte eines Typs in der Sprache der Seite, neueste zuerst (Feld `date`, sonst Veröffentlichung), gefiltert über
 * die Adresse — `?kategorie=`, `?schlagwort=`, `?autor=`, `?archiv=2026-10` — und seitenweise (`?seite=`).
 * Die Reihenfolge wählt das Theme mit dem dritten Wert von `entries()`: `neu` (Vorgabe), `alt`, `kommend` (nur mit
 * Datum ab heute, das nächste zuerst — Anlässe) oder `reihenfolge` (Feld `position`, sonst Seitenbaum — Speisekarte).
 * `{% pagination %}`, `{% categories "post" %}` und `{% archive "post" %}` nutzen dieselbe Liste. Eine Instanz je
 * gerenderter Seite (sie merkt sich die letzte Liste für die Paginierung).
 */
final class Listings
{
    /** @var array{type: string, page: int, pages: int, total: int}|null */
    private ?array $last = null;

    /** @param  Closure(string, array<string, mixed>): array<string, mixed>  $prepare  Felder eines Typs fürs Theme (Bilder, Links, Fliesstext) */
    public function __construct(private readonly Site $site, private readonly string $locale, private readonly Request $request, private readonly Pages $pages, private readonly Closure $prepare) {}

    /** Reihenfolgen für den dritten Wert von `entries()` */
    public const ORDERS = ['neu', 'alt', 'kommend', 'reihenfolge'];

    /** @return list<array<string, mixed>> */
    public function entries(string $type, int $per, string $order = 'neu'): array
    {
        $per = max(1, min(200, $per));
        $all = $this->ordered($this->published($type), $order);
        $filters = $this->filters();
        $all = array_values(array_filter($all, static function (array $item) use ($filters): bool {
            return ($filters['kategorie'] === '' || Str::slug((string) ($item['category'] ?? '')) === $filters['kategorie'])
                && ($filters['schlagwort'] === '' || in_array($filters['schlagwort'], array_map(static fn (array $t): string => $t['slug'], $item['tags']), true))
                && ($filters['autor'] === '' || ($item['author_slug'] ?? '') === $filters['autor'])
                && ($filters['archiv'] === '' || $item['month'] === $filters['archiv']);
        }));
        $pages = max(1, (int) ceil(count($all) / $per));
        $page = max(1, min($pages, (int) $this->request->query('seite', 1)));
        $this->last = ['type' => $type, 'page' => $page, 'pages' => $pages, 'total' => count($all)];

        return array_slice($all, ($page - 1) * $per, $per);
    }

    /**
     * @param  list<array<string, mixed>>  $items  neueste zuerst (aus published())
     * @return list<array<string, mixed>>
     */
    private function ordered(array $items, string $order): array
    {
        if ($order === 'alt') {
            usort($items, static fn (array $a, array $b): int => [$a['timestamp'], $a['position'], $a['title']] <=> [$b['timestamp'], $b['position'], $b['title']]);
        } elseif ($order === 'kommend') {
            $today = CarbonImmutable::now($this->site->timezone ?: config('app.timezone'))->startOfDay()->format('Y-m-d');
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['dated'] && (string) $item['date'] >= $today));
            usort($items, static fn (array $a, array $b): int => [$a['timestamp'], $a['position'], $a['title']] <=> [$b['timestamp'], $b['position'], $b['title']]);
        } elseif ($order === 'reihenfolge') {
            usort($items, static fn (array $a, array $b): int => [$a['position'] === PHP_INT_MAX ? $a['tree_position'] : $a['position'], $a['title']] <=> [$b['position'] === PHP_INT_MAX ? $b['tree_position'] : $b['position'], $b['title']]);
        }

        return $items;
    }

    public function pagination(): string
    {
        if ($this->last === null || $this->last['pages'] <= 1) {
            return '';
        }
        $t = $this->texts();
        $html = sprintf('<nav class="pagination" aria-label="%s"><ul>', $t['pages']);
        if ($this->last['page'] > 1) {
            $html .= sprintf('<li><a href="%s" rel="prev">%s</a></li>', $this->url(['seite' => $this->last['page'] - 1]), $t['prev']);
        }
        for ($i = 1; $i <= $this->last['pages']; $i++) {
            $html .= $i === $this->last['page'] ? sprintf('<li><span aria-current="page">%d</span></li>', $i) : sprintf('<li><a href="%s">%d</a></li>', $this->url(['seite' => $i]), $i);
        }
        if ($this->last['page'] < $this->last['pages']) {
            $html .= sprintf('<li><a href="%s" rel="next">%s</a></li>', $this->url(['seite' => $this->last['page'] + 1]), $t['next']);
        }

        return $html.'</ul></nav>';
    }

    /** Kategorien mit Zahl, als Links auf diese Seite mit `?kategorie=`. */
    public function categories(string $type): string
    {
        $counts = [];
        foreach ($this->published($type) as $item) {
            if (($item['category'] ?? '') !== '') {
                $counts[(string) $item['category']] = ($counts[(string) $item['category']] ?? 0) + 1;
            }
        }
        ksort($counts);
        $current = $this->filters()['kategorie'];
        $html = sprintf('<nav class="categories" aria-label="%s"><ul>', $this->texts()['categories']);
        foreach ($counts as $name => $n) {
            $slug = Str::slug($name);
            $html .= sprintf('<li><a href="%s"%s>%s</a> <span class="count">%d</span></li>', $this->url(['kategorie' => $slug], true), $slug === $current ? ' aria-current="page"' : '', e($name), $n);
        }

        return $html.'</ul></nav>';
    }

    /** Monate mit Beiträgen, neueste zuerst, als Links mit `?archiv=JJJJ-MM`. */
    public function archive(string $type): string
    {
        $months = [];
        foreach ($this->published($type) as $item) {
            $months[$item['month']] = ($months[$item['month']] ?? 0) + 1;
        }
        krsort($months);
        $html = sprintf('<nav class="archive" aria-label="%s"><ul>', $this->texts()['archive']);
        foreach ($months as $month => $n) {
            // mit Tag 1 — sonst nimmt Carbon den heutigen Tag, und am 31. wird aus Februar der März
            $label = Carbon::createFromFormat('Y-m-d', $month.'-01')?->settings(['locale' => $this->locale])->isoFormat('MMMM YYYY') ?? $month;
            $html .= sprintf('<li><a href="%s">%s</a> <span class="count">%d</span></li>', $this->url(['archiv' => $month], true), e($label), $n);
        }

        return $html.'</ul></nav>';
    }

    /** @return list<array<string, mixed>> veröffentlichte Inhalte des Typs, neueste zuerst */
    private function published(string $type): array
    {
        $entries = Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->where('type', $type)->whereNull('deleted_at')->get()
            ->each(fn (Entry $e) => $e->setRelation('site', $this->site));
        if ($entries->isEmpty()) {
            return [];
        }
        $chain = $this->site->fallbackChain($this->locale);
        $versions = EntryVersion::query()->whereIn('entry_id', $entries->modelKeys())->whereIn('locale', $chain)->where('status', EntryStatus::Published->value)->get()
            ->groupBy('entry_id');
        $items = [];
        foreach ($entries as $entry) {
            // TB 13 Scheibe 2: Geschütztes nur für Mitglieder (Gäste-Seiten kommen aus dem Cache)
            if (! $this->pages->isVisible($entry) || ! app(PageAccess::class)->mayView($entry)) {
                continue;
            }
            $version = null;
            foreach ($chain as $code) {
                $version ??= $versions->get($entry->id)?->firstWhere('locale', $code);
            }
            $url = $version === null ? null : $this->pages->urlOf($entry, $this->locale);
            if ($version === null || $url === null) {
                continue;
            }
            $data = $version->data;
            $own = $this->date($data['date'] ?? null);
            $date = $own ?? $version->published_at ?? $version->created_at;
            $author = $this->author($data['author'] ?? null);
            $tags = array_values(array_filter(array_map(static fn (mixed $t): string => trim((string) $t), is_array($data['tags'] ?? null) ? $data['tags'] : (is_string($data['tags'] ?? null) ? explode(',', $data['tags']) : []))));
            $items[] = [
                'title' => $version->title, 'url' => $url, 'intro' => RichText::toHtml($data['intro'] ?? null),
                'date' => $date?->format('Y-m-d'), 'month' => $date?->format('Y-m') ?? '', 'timestamp' => $date?->getTimestamp() ?? 0,
                'category' => is_string($data['category'] ?? null) ? $data['category'] : null,
                'category_url' => is_string($data['category'] ?? null) && $data['category'] !== '' ? $this->url(['kategorie' => Str::slug($data['category'])], true) : null,
                'tags' => array_map(fn (string $t): array => ['name' => $t, 'slug' => Str::slug($t), 'url' => $this->url(['schlagwort' => Str::slug($t)], true)], $tags),
                'author' => $author['name'] ?? null, 'author_slug' => $author['slug'] ?? null,
                'author_url' => $author === null ? null : $this->url(['autor' => $author['slug']], true),
                // Reihenfolge bei gleichem Datum, etwa die Doku in der Ordnung ihrer Bereiche (DocsImporter)
                'position' => is_numeric($data['position'] ?? null) ? (int) $data['position'] : PHP_INT_MAX,
                // für «reihenfolge» (Platz im Seitenbaum) und «kommend» (nur Inhalte mit eigenem Datum)
                'tree_position' => (int) $entry->position, 'dated' => $own !== null,
            ] + ($this->prepare)($type, $data);
        }
        usort($items, static fn (array $a, array $b): int => [$b['timestamp'], $a['position'], $a['title']] <=> [$a['timestamp'], $b['position'], $b['title']]);

        return $items;
    }

    /** @return array{name: string, slug: string}|null Autor aus einer Beziehung auf eine Person (Id oder Seed-Name) */
    private function author(mixed $ref): ?array
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        $query = Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id);
        $person = is_numeric($ref) ? (clone $query)->find((int) $ref)
            : (clone $query)->where('uuid', Uuid::uuid5(Uuid::NAMESPACE_URL, sprintf('basalto-seed:%d:person:%s', $this->site->id, (string) $ref))->toString())->first();
        $version = $person?->resolve($this->locale);
        $name = $version === null ? (is_string($ref) ? $ref : null) : (string) ($version->data['name'] ?? $version->title);

        return $name === null ? null : ['name' => $name, 'slug' => Str::slug($name)];
    }

    private function date(mixed $value): ?CarbonInterface
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{kategorie: string, schlagwort: string, autor: string, archiv: string} */
    private function filters(): array
    {
        $get = fn (string $key): string => is_string($this->request->query($key)) ? Str::slug((string) $this->request->query($key)) : '';
        $archive = is_string($this->request->query('archiv')) && preg_match('/^\d{4}-\d{2}$/', (string) $this->request->query('archiv')) === 1 ? (string) $this->request->query('archiv') : '';

        return ['kategorie' => $get('kategorie'), 'schlagwort' => $get('schlagwort'), 'autor' => $get('autor'), 'archiv' => $archive];
    }

    /** @param  array<string, string|int>  $set  ohne `$fresh` bleiben die übrigen Filter erhalten */
    private function url(array $set, bool $fresh = false): string
    {
        $query = $fresh ? [] : array_filter($this->filters());
        $query = array_merge($query, $set);
        if (($query['seite'] ?? null) === 1) {
            unset($query['seite']);
        }

        return e($this->request->getPathInfo().($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @return array{pages: string, prev: string, next: string, categories: string, archive: string} */
    private function texts(): array
    {
        return match ($this->locale) {
            'en' => ['pages' => 'Pages', 'prev' => 'Newer', 'next' => 'Older', 'categories' => 'Categories', 'archive' => 'Archive'],
            'it' => ['pages' => 'Pagine', 'prev' => 'Più recenti', 'next' => 'Meno recenti', 'categories' => 'Categorie', 'archive' => 'Archivio'],
            'fr' => ['pages' => 'Pages', 'prev' => 'Plus récents', 'next' => 'Plus anciens', 'categories' => 'Catégories', 'archive' => 'Archives'],
            default => ['pages' => 'Seiten', 'prev' => 'Neuere', 'next' => 'Ältere', 'categories' => 'Kategorien', 'archive' => 'Archiv'],
        };
    }
}
