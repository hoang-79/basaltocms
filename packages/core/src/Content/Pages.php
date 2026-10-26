<?php

declare(strict_types=1);

namespace Basalto\Core\Content;

use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Redirect;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Der Seitenbaum einer Site (M-01): Pfade je Sprache aus den Slugs der Eltern (Ordner und Fehlerseiten tragen
 * nichts bei, die Startseite liegt auf «/»), Routen der Inhaltstypen aus Lava, Auflösen eines Pfads auf eine
 * veröffentlichte Fassung, Verschieben und Slug-Wechsel mit Umleitungen (301).
 *
 * @phpstan-type Hit array{entry: Entry, version: EntryVersion, path: string}
 */
final class Pages
{
    /** @var array<string, array{paths: array<string, int>, byEntry: array<int, string>, versions: array<int, EntryVersion>}> je «site:locale:p|l» */
    private array $maps = [];

    /** @var array<int, Collection<int, Entry>> Einträge je Site — einmal je Anfrage geladen, nicht je Zeile (N-03) */
    private array $entries = [];

    /** @var array<string, int|null> Startseite je «site:locale» */
    private array $homes = [];

    /** @var array<int, array<int, bool>> TB 13 Scheibe 2: geschützt ja/nein je Site und Eintrag — einmal je Anfrage */
    private array $protected = [];

    public function __construct(private readonly ThemeLoader $themes) {}

    public function forget(): void
    {
        $this->maps = [];
        $this->entries = [];
        $this->homes = [];
        $this->protected = [];
    }

    /**
     * Sprachpräfix und Pfad trennen: «/en/services» → [en, /services]; die Standardsprache hat kein Präfix.
     *
     * @return array{0: string, 1: string}
     */
    public function splitLocale(Site $site, string $path): array
    {
        $path = '/'.trim($path, '/');
        $first = explode('/', trim($path, '/'))[0];
        foreach ($site->localeCodes() as $locale) {
            if ($locale !== $site->default_locale && $first === $locale) {
                $rest = substr($path, strlen('/'.$locale));

                return [$locale, $rest === '' ? '/' : $rest];
            }
        }

        return [$site->default_locale, $path];
    }

    /** Der Pfad mit Sprachpräfix, wie er in der Adresszeile steht («/», «/en», «/en/services»). */
    public function urlPath(Site $site, string $locale, string $path): string
    {
        $prefix = $locale === $site->default_locale ? '' : '/'.$locale;
        if ($path === '/' || $path === '') {
            return $prefix === '' ? '/' : $prefix;
        }

        return $prefix.$path;
    }

    /** Pfad einer Seite in einer Sprache (ohne Präfix) — veröffentlicht, oder für das Backend aus den neuesten Fassungen. */
    public function pathOf(Entry $entry, string $locale, bool $published = true): ?string
    {
        return $this->mapFor($entry->site, $locale, $published)['byEntry'][$entry->id] ?? null;
    }

    /**
     * Pfad nur, wenn die Seite in dieser Sprache eine eigene veröffentlichte Fassung hat — nicht über den Fallback.
     * Für hreflang und Sitemap: eine deutsch antwortende `/en/…`-Seite wäre doppelter Inhalt.
     */
    public function ownPathOf(Entry $entry, string $locale): ?string
    {
        $path = $this->pathOf($entry, $locale);

        return $path !== null && $this->versionOf($entry, $locale)?->locale === $locale ? $path : null;
    }

    public function urlOf(Entry $entry, string $locale, bool $published = true): ?string
    {
        $path = $this->pathOf($entry, $locale, $published);

        return $path === null ? null : $this->urlPath($entry->site, $locale, $path);
    }

    /**
     * Der Pfad, den ein Slug unter einer Elternseite bekäme (Pfad-Vorschau und Kollisionsprüfung im Formular).
     * Null, wenn ein Vorfahre in der Sprache keine Fassung hat.
     */
    public function pathFor(Site $site, ?int $parentId, string $locale, string $slug, bool $published = false): ?string
    {
        $map = $this->mapFor($site, $locale, $published);
        $entries = $this->entriesOf($site);
        $parentPath = '';
        for ($node = $parentId === null ? null : $entries->find($parentId); $node !== null; $node = $node->parent_id === null ? null : $entries->find($node->parent_id)) {
            $type = $node->pageType();
            if ($type === null) {
                break;
            }
            if ($this->homeId($site, $entries, $locale) === $node->id) {
                break;
            }
            if (! $type->hasSlug()) {
                continue;
            }
            $path = $map['byEntry'][$node->id] ?? null;
            if ($path === null) {
                return null;
            }
            $parentPath = $path;
            break;
        }

        return rtrim($parentPath, '/').'/'.$slug;
    }

    /**
     * Die massgebliche Fassung eines Eintrags in einer Sprache (entlang der Fallback-Kette) — veröffentlicht oder neueste.
     */
    public function versionOf(Entry $entry, string $locale, bool $published = true): ?EntryVersion
    {
        return $this->mapFor($entry->site, $locale, $published)['versions'][$entry->id] ?? null;
    }

    /**
     * Alle Einträge einer Site in Baumreihenfolge (Position, dann Nummer) — für Navigation und Brotkrumen.
     *
     * @return Collection<int, Entry>
     */
    public function entries(Site $site): Collection
    {
        return $this->entriesOf($site);
    }

    /**
     * Ziele für Links im Editor (TB 30): Adresse (mit Sprachpräfix) und Titel jeder Seite und jedes Inhalts mit eigener
     * Adresse, nach den neuesten Fassungen — die Redaktion verlinkt auch, was erst entsteht. Gefiltert nach Titel oder
     * Adresse, Startseite zuerst, dann kürzere Adressen.
     *
     * @return list<array{path: string, title: string}>
     */
    public function linkTargets(Site $site, string $locale, string $query = '', int $limit = 20): array
    {
        $map = $this->mapFor($site, $locale, false);
        $needle = mb_strtolower(trim($query));
        $targets = [];
        foreach ($map['byEntry'] as $id => $path) {
            $title = (string) ($map['versions'][$id]->title ?? '');
            $url = $this->urlPath($site, $locale, $path);
            if ($needle === '' || str_contains(mb_strtolower($title.' '.$url), $needle)) {
                $targets[] = ['path' => $url, 'title' => $title];
            }
        }
        usort($targets, static fn (array $a, array $b): int => [substr_count($a['path'], '/'), strlen($a['path'])] <=> [substr_count($b['path'], '/'), strlen($b['path'])]);

        return array_slice($targets, 0, $limit);
    }

    /** Welcher Eintrag liegt an einem Pfad — veröffentlicht oder nach den neuesten Fassungen? */
    public function entryIdAt(Site $site, string $locale, string $path, bool $published = true): ?int
    {
        return $this->mapFor($site, $locale, $published)['paths']['/'.trim($path, '/')] ?? null;
    }

    /** Ein freier Slug neben einem vergebenen: «services-2», «services-3» … */
    public function freeSlug(Site $site, ?int $parentId, string $locale, string $slug, ?int $exceptId = null): string
    {
        for ($n = 2; $n < 100; $n++) {
            $candidate = $slug.'-'.$n;
            $path = $this->pathFor($site, $parentId, $locale, $candidate, false);
            $taken = $path === null ? null : $this->entryIdAt($site, $locale, $path, false);
            if ($taken === null || $taken === $exceptId) {
                return $candidate;
            }
        }

        return $slug.'-'.bin2hex(random_bytes(2));
    }

    /** Die Startseite einer Sprache: `settings.home.<locale>` der Site, sonst die erste Seite der obersten Ebene. */
    public function isHome(Entry $entry, string $locale): bool
    {
        $site = $entry->site;

        return ($this->homes[$site->id.':'.$locale] ??= $this->homeId($site, $this->entriesOf($site), $locale)) === $entry->id;
    }

    /**
     * Löst einen Pfad (ohne Präfix) in einer Sprache auf: die Seite oder der Inhalt mit genau diesem Pfad, dessen
     * Fassung veröffentlicht und im Zeitfenster sichtbar ist.
     *
     * @return Hit|null
     */
    public function resolve(Site $site, string $locale, string $path): ?array
    {
        $path = '/'.trim($path, '/');
        $map = $this->mapFor($site, $locale, true);
        $id = $map['paths'][$path] ?? null;
        if ($id === null) {
            return null;
        }
        $entry = $this->entriesOf($site)->find($id);
        $version = $map['versions'][$id] ?? null;
        if ($entry === null || $version === null || ! $this->isVisible($entry)) {
            return null;
        }
        // Die Pfadtabelle trägt schlanke Fassungen — zum Rendern die eine vollständig
        $version = EntryVersion::query()->find($version->id) ?? $version;

        return ['entry' => $entry, 'version' => $version, 'path' => $path];
    }

    /**
     * Die Fehlerseite einer Site für einen Code in einer Sprache — veröffentlicht, sonst null.
     *
     * @return Hit|null
     */
    public function errorPage(Site $site, string $locale, int $code): ?array
    {
        $type = PageType::forErrorCode($code);
        if ($type === null) {
            return null;
        }
        foreach ($this->entriesOf($site) as $entry) {
            if ($entry->type !== $type->value) {
                continue;
            }
            $version = $entry->resolve($locale);
            if ($version !== null) {
                return ['entry' => $entry, 'version' => $version, 'path' => '/'];
            }
        }

        return null;
    }

    /** Sichtbar im Zeitfenster «anzeigen ab/bis» (Reiter Veröffentlichung) — in der Zeitzone der Site. */
    public function isVisible(Entry $entry, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $timezone = $entry->site->timezone;
        $from = $entry->setting('publication.visible_from');
        $until = $entry->setting('publication.visible_until');
        if (is_string($from) && $from !== '' && CarbonImmutable::parse($from, $timezone)->isAfter($now)) {
            return false;
        }
        if (is_string($until) && $until !== '' && CarbonImmutable::parse($until, $timezone)->isBefore($now)) {
            return false;
        }

        return true;
    }

    /**
     * TB 13 Scheibe 2 (M-23): Nur für Mitglieder? Ja, wenn die Seite selbst geschützt ist (Reiter «Zugriff») oder ein
     * Vorfahre, dessen Schutz für Unterseiten gilt (`access.children`, ab Werk an). Eine Unterseite kann den Schutz
     * eines Vorfahren nicht aufheben — wer Teile öffnen will, schaltet beim Vorfahren «gilt für Unterseiten» aus.
     */
    public function isProtected(Entry $entry): bool
    {
        $site = $entry->site;
        if (! isset($this->protected[$site->id])) {
            $byId = $this->entriesOf($site)->keyBy('id');
            $flags = [];
            foreach ($byId as $id => $node) {
                $flags[(int) $id] = self::protects($node, false);
            }
            foreach ($byId as $id => $node) {
                for ($parent = $node->parent_id === null ? null : $byId->get($node->parent_id), $depth = 0; ! $flags[(int) $id] && $parent !== null && $depth < 100;
                    $parent = $parent->parent_id === null ? null : $byId->get($parent->parent_id), $depth++) {
                    $flags[(int) $id] = self::protects($parent, true);
                }
            }
            $this->protected[$site->id] = $flags;
        }

        return $this->protected[$site->id][$entry->id] ?? self::protects($entry, false);
    }

    /** Der nächste Vorfahre ab `$parentId` (einschliesslich), dessen Schutz für Unterseiten gilt — für den Reiter «Zugriff». */
    public function protectingAncestor(Site $site, ?int $parentId): ?Entry
    {
        $byId = $this->entriesOf($site)->keyBy('id');
        for ($node = $parentId === null ? null : $byId->get($parentId), $depth = 0; $node !== null && $depth < 100; $node = $node->parent_id === null ? null : $byId->get($node->parent_id), $depth++) {
            if (self::protects($node, true)) {
                return $node;
            }
        }

        return null;
    }

    /** Schützt dieser Eintrag sich selbst — oder, als Vorfahre, seine Unterseiten? */
    private static function protects(Entry $entry, bool $asAncestor): bool
    {
        if (! in_array($entry->setting('access.protected', false), [true, 1, '1', 'true'], true)) {
            return false;
        }

        return ! $asAncestor || ! in_array($entry->setting('access.children', true), [false, 0, '0', 'false'], true);
    }

    /** Ende des Zeitfensters in Sekunden ab jetzt (für Cache-Kopfzeilen), null ohne Ende. */
    public function secondsUntilHidden(Entry $entry): ?int
    {
        $until = $entry->setting('publication.visible_until');
        if (! is_string($until) || $until === '') {
            return null;
        }

        return max(0, (int) CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($until, $entry->site->timezone), false));
    }

    /**
     * Die Variablen einer ausgelieferten Seite für das Theme: Pfad, URL, Startseite, kanonische URL, Seitenbild,
     * Alternativen je Sprache (Sprachwechsler, auch über den Fallback) und `hreflang` (nur eigene Fassungen).
     *
     * @return array<string, mixed>
     */
    public function pageVariables(Site $site, Entry $entry, EntryVersion $version, string $locale, string $path, string $base): array
    {
        $url = rtrim($base, '/').$this->urlPath($site, $locale, $path);
        $canonical = $entry->setting('canonical', []);
        $canonicalUrl = is_array($canonical) && ($canonical['mode'] ?? 'auto') === 'custom' && is_string($canonical['url'] ?? null) && $canonical['url'] !== '' ? $canonical['url'] : $url;
        $alternates = [];
        $hreflang = [];
        foreach ($site->localeCodes() as $code) {
            $alt = $this->pathOf($entry, $code, true);
            if ($alt !== null) {
                $alternates[] = ['locale' => $code, 'url' => rtrim($base, '/').$this->urlPath($site, $code, $alt)];
                if ($this->ownPathOf($entry, $code) !== null) {
                    $hreflang[] = ['locale' => $code, 'url' => rtrim($base, '/').$this->urlPath($site, $code, $alt)];
                }
            }
        }

        return [
            'path' => $path,
            'url' => $url,
            'locale' => $locale,
            'is_home' => $this->isHome($entry, $locale),
            'canonical' => $canonicalUrl,
            'image' => $this->pageImage($entry),
            'alternates' => $alternates,
            'hreflang' => $hreflang,
        ];
    }

    /**
     * Pfadänderungen rund um eine Änderung (Verschieben, Veröffentlichen mit neuem Slug) verfolgen und Umleitungen
     * schreiben: alter Pfad → neuer Pfad je Sprache, auch für Nachkommen; Ketten werden aufgelöst, wieder besetzte
     * Pfade verlieren ihre Umleitung. Der Rückgabewert ist der der Änderung.
     */
    public function trackPaths(Site $site, Closure $change): mixed
    {
        $before = $this->snapshot($site);
        $result = $change();
        $this->forget();
        $after = $this->snapshot($site);
        $entries = $this->entriesOf($site);
        foreach ($after as $locale => $paths) {
            foreach ($paths as $id => $new) {
                $old = $before[$locale][$id] ?? null;
                if ($old === null || $old === $new) {
                    continue;
                }
                $entry = $entries->find($id);
                if ($entry === null || $entry->setting('routing.redirect_on_slug_change', true) === false) {
                    continue;
                }
                $from = $this->urlPath($site, $locale, $old);
                $to = $this->urlPath($site, $locale, $new);
                $query = Redirect::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
                (clone $query)->where('to_path', $from)->update(['to_path' => $to]);
                (clone $query)->where('from_path', $to)->delete();
                Redirect::query()->withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'locale' => $locale, 'from_path' => $from],
                    ['to_path' => $to, 'status' => 301, 'source' => Redirect::SOURCE_SLUG_CHANGE],
                );
            }
        }

        return $result;
    }

    /** Eine Seite verschieben: neue Elternseite und Position; Geschwister werden neu nummeriert, Umleitungen geschrieben. */
    public function move(Entry $entry, ?int $parentId, int $position): void
    {
        $site = $entry->site;
        $this->forget();
        $entries = $this->entriesOf($site);
        if ($parentId !== null) {
            $parent = $entries->find($parentId) ?? throw new InvalidArgumentException(__('Die Elternseite gehört nicht zu dieser Site.'));
            for ($node = $parent; $node !== null; $node = $node->parent_id === null ? null : $entries->find($node->parent_id)) {
                if ($node->id === $entry->id) {
                    throw new InvalidArgumentException(__('Eine Seite kann nicht unter sich selbst liegen.'));
                }
            }
            $parentType = $parent->pageType();
            if ($parentType === null || $parentType->isError()) {
                throw new InvalidArgumentException(__('Unter diesem Eintrag kann keine Seite liegen.'));
            }
        }
        $this->trackPaths($site, function () use ($entry, $entries, $parentId, $position): void {
            $entry->parent_id = $parentId;
            $entry->save();
            // Nur Seiten des Baums zählen als Geschwister — Inhaltstypen (project, person) liegen daneben, nicht dazwischen
            $siblings = $entries->filter(static fn (Entry $e): bool => $e->parent_id === $parentId && $e->id !== $entry->id && PageType::isPageType($e->type))->sortBy(['position', 'id'])->values();
            $ordered = $siblings->all();
            array_splice($ordered, max(0, min($position, count($ordered))), 0, [$entry]);
            foreach ($ordered as $i => $sibling) {
                if ($sibling->position !== $i) {
                    $sibling->position = $i;
                    $sibling->save();
                }
            }
        });
        $this->forget();
    }

    /**
     * Alle Pfade einer Sprache: Pfad → entry_id. Veröffentlicht für das Frontend; mit `$published = false` aus den
     * neuesten Fassungen für die Vorschau im Backend. Eine Site lädt ihre Einträge und Fassungen in zwei Abfragen.
     *
     * @return array{paths: array<string, int>, byEntry: array<int, string>, versions: array<int, EntryVersion>}
     */
    private function mapFor(Site $site, string $locale, bool $published): array
    {
        $key = $site->id.':'.$locale.':'.($published ? 'p' : 'l');
        if (isset($this->maps[$key])) {
            return $this->maps[$key];
        }
        $entries = $this->entriesOf($site);
        $versions = $this->versionsFor($site, $entries, $locale, $published);
        $home = $this->homeId($site, $entries, $locale);

        return $this->maps[$key] = $this->buildPaths($entries, $versions, $home, $locale, $this->typeRoutes($site)) + ['versions' => $versions];
    }

    /**
     * Pfade des Seitenbaums für das Backend aus schon geladenen Seiten (mit `versions`): dieselbe Rechnung wie die
     * Pfadtabelle, aber ohne alle Inhalte der Site und ihre Fassungen neu zu laden (N-03 — bei 10'000 Inhalten
     * zeigt der Baum vielleicht 1'000 Seiten). Neueste Fassung entlang der Fallback-Kette, wie im Backend.
     *
     * @param  Collection<int, Entry>  $pages
     * @return array{home: int|null, byEntry: array<int, string>}
     */
    public function treePaths(Site $site, Collection $pages, string $locale): array
    {
        $versions = [];
        foreach ($site->fallbackChain($locale) as $code) {
            foreach ($pages as $entry) {
                if (isset($versions[$entry->id])) {
                    continue;
                }
                $latest = null;
                foreach ($entry->versions as $version) {
                    if ($version->locale === $code && ($latest === null || $version->number > $latest->number)) {
                        $latest = $version;
                    }
                }
                if ($latest !== null) {
                    $versions[$entry->id] = $latest;
                }
            }
        }
        $home = $this->homeId($site, $pages, $locale);

        return ['home' => $home, 'byEntry' => $this->buildPaths($pages, $versions, $home, $locale, [])['byEntry']];
    }

    /**
     * @param  Collection<int, Entry>  $entries
     * @param  array<int, EntryVersion>  $versions
     * @param  array<string, array<string, string>>  $routes
     * @return array{paths: array<string, int>, byEntry: array<int, string>}
     */
    private function buildPaths(Collection $entries, array $versions, ?int $home, string $locale, array $routes): array
    {
        $byId = $entries->keyBy('id');
        $paths = [];
        $byEntry = [];
        $parentMemo = [];
        foreach ($entries as $entry) {
            $type = $entry->pageType();
            $version = $versions[$entry->id] ?? null;
            if ($type === null) {
                $pattern = $routes[$entry->type][$locale] ?? $routes[$entry->type]['*'] ?? null;
                if ($pattern === null || $version === null) {
                    continue;
                }
                $path = '/'.trim(str_replace('{slug}', $version->slug, $pattern), '/');
            } elseif ($entry->id === $home) {
                $path = '/';
            } elseif (! $type->hasSlug() || $version === null) {
                continue;
            } else {
                $parentPath = $this->parentPath($entry, $byId, $versions, $home, $parentMemo);
                if ($parentPath === null) {
                    continue;
                }
                $path = $parentPath.'/'.$version->slug;
            }
            // Gleicher Pfad: die höhere Routenpriorität (Reiter «Routing») gewinnt, bei Gleichstand die erste im Baum
            $holder = isset($paths[$path]) ? $byId->get($paths[$path]) : null;
            if ($holder === null || self::priority($entry) > self::priority($holder)) {
                $paths[$path] = $entry->id;
            }
            $byEntry[$entry->id] = $path;
        }

        return ['paths' => $paths, 'byEntry' => $byEntry];
    }

    private static function priority(Entry $entry): int
    {
        $settings = is_array($entry->settings) ? $entry->settings : [];

        return (int) ($settings['routing']['priority'] ?? 0);
    }

    /**
     * Der Pfad der Eltern («» an der Wurzel, null wenn ein Vorfahre keine Fassung hat): Ordner und Fehlerseiten
     * werden übersprungen, unter der Startseite liegen Kinder direkt unter «/».
     *
     * @param  Collection<int, Entry>  $byId
     * @param  array<int, EntryVersion>  $versions
     * @param  array<int, string|null>  $memo
     */
    private function parentPath(Entry $entry, Collection $byId, array $versions, ?int $home, array &$memo): ?string
    {
        $parent = $entry->parent_id === null ? null : $byId->get($entry->parent_id);
        if ($parent === null) {
            return '';
        }
        if (array_key_exists($parent->id, $memo)) {
            return $memo[$parent->id];
        }
        $type = $parent->pageType();
        if ($parent->id === $home || $type === null) {
            return $memo[$parent->id] = '';
        }
        if (! $type->hasSlug()) {
            return $memo[$parent->id] = $this->parentPath($parent, $byId, $versions, $home, $memo);
        }
        $version = $versions[$parent->id] ?? null;
        $above = $this->parentPath($parent, $byId, $versions, $home, $memo);
        if ($version === null || $above === null) {
            return $memo[$parent->id] = null;
        }

        return $memo[$parent->id] = $above.'/'.$version->slug;
    }

    /**
     * Die massgebliche Fassung je Eintrag in einer Sprache entlang der Fallback-Kette der Site (R-20):
     * veröffentlicht, oder für das Backend die neueste.
     *
     * @param  Collection<int, Entry>  $entries
     * @return array<int, EntryVersion>
     */
    private function versionsFor(Site $site, Collection $entries, string $locale, bool $published): array
    {
        $chain = $site->fallbackChain($locale);
        // Schlank: ohne `data` — die Tabelle braucht Titel, Slug, Sprache, Status und Zeitpunkte; bei 10'000 Inhalten
        // macht das JSON den Grossteil der Zeit aus (N-03). Wer rendert, lädt die eine Fassung vollständig (resolve).
        $query = EntryVersion::query()->select(['id', 'entry_id', 'locale', 'number', 'status', 'title', 'slug', 'source_version_id', 'scheduled_for', 'published_at', 'created_at', 'updated_at'])
            ->whereIn('entry_id', $entries->modelKeys())->whereIn('locale', $chain)->orderByDesc('number');
        if ($published) {
            $query->where('status', EntryStatus::Published->value);
        }
        $all = $query->get();
        $versions = [];
        foreach ($chain as $code) {
            foreach ($all as $version) {
                if ($version->locale === $code && ! isset($versions[$version->entry_id])) {
                    $versions[$version->entry_id] = $version;
                }
            }
        }

        return $versions;
    }

    /** @param  Collection<int, Entry>  $entries */
    private function homeId(Site $site, Collection $entries, string $locale): ?int
    {
        $chosen = $site->setting('home.'.$locale) ?? $site->setting('home.'.$site->default_locale);
        if (is_numeric($chosen) && $entries->find((int) $chosen) !== null) {
            return (int) $chosen;
        }
        $first = $entries->first(static fn (Entry $e): bool => $e->parent_id === null && $e->type === PageType::Page->value);

        return $first?->id;
    }

    /** @return Collection<int, Entry> */
    private function entriesOf(Site $site): Collection
    {
        return $this->entries[$site->id] ??= Entry::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('position')->orderBy('id')->get()
            ->each(static fn (Entry $entry) => $entry->setRelation('site', $site));
    }

    /**
     * Routen der Inhaltstypen aus Lava («route: /projekte/{slug}», «route.en: …»): Typ → Sprache → Muster.
     *
     * @return array<string, array<string, string>>
     */
    private function typeRoutes(Site $site): array
    {
        try {
            $schema = ThemeSchema::fromTheme($this->themes->forSite($site));
        } catch (ThemeException) {
            return [];
        }
        $routes = [];
        foreach ($schema->types as $name => $type) {
            if (PageType::isPageType($name)) {
                continue;
            }
            $routes[$name] = $type['routes'] !== [] ? $type['routes'] : ['*' => '/'.$name.'/{slug}'];
        }

        return $routes;
    }

    /** @return array<string, array<int, string>> Sprache → entry_id → Pfad (veröffentlicht) */
    private function snapshot(Site $site): array
    {
        $out = [];
        foreach ($site->localeCodes() as $locale) {
            $out[$locale] = $this->mapFor($site, $locale, true)['byEntry'];
        }

        return $out;
    }

    /** @return array{src: string, alt: string}|null */
    private function pageImage(Entry $entry): ?array
    {
        $id = $entry->setting('image.media_id');
        if (! is_numeric($id)) {
            return null;
        }
        $media = Media::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $entry->site_id)->find((int) $id);
        if ($media === null) {
            return null;
        }

        return ['src' => $media->url($media->variants ? 'content' : null), 'alt' => (string) ($entry->setting('image.alt') ?? $media->name)];
    }
}
