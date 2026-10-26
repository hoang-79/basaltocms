<?php

declare(strict_types=1);

namespace Basalto\Core\Navigation;

use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Content\PageAccess;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Value;
use Basalto\Lava\Theme\Theme;
use Illuminate\Support\Collection;

/**
 * Navigation (M-07, L-08): die Menüs des Themes aus dem Seitenbaum der Datenbank (`from: pages`), aus einer Typ-Liste
 * (`from: <typ>`), aus freien Einträgen der Station D (`items: db`) und aus festen Einträgen im Theme (`item`).
 *
 * Seitenbaum: nur veröffentlichte, sichtbare Seiten (Fallback-Kette der Sprache), ohne «Im Menü verbergen»,
 * Fehlerseiten und Feeds; Ordner erscheinen nicht, ihre Kinder rücken an ihre Stelle. `start` wählt die Ebene
 * (1 = oberste, 2 = Kinder der aktiven obersten Seite …), `depth` die Zahl der Ebenen, `only`/`exclude` filtern nach Template.
 *
 * @phpstan-type Item array{label: string, href: string, current: bool, active: bool, new_window: bool, entry_id: int|null, children: list<mixed>}
 */
final class Navigation
{
    /** @var array<int, Collection<int, Entry>> */
    private array $entries = [];

    public function __construct(private readonly Pages $pages) {}

    /**
     * Die Einträge eines Menüs für eine Sprache — mit aktivem Zustand zur aktuellen Seite.
     *
     * @return list<Item>
     */
    public function items(Site $site, Theme $theme, Definition $nav, string $locale, ?Entry $current = null, ?string $currentPath = null): array
    {
        $depth = max(1, (int) ($nav->value('depth') ?? 1));
        $items = [];
        $from = $nav->value('from');
        if ($from === 'pages') {
            $items = $this->fromTree($site, $theme, $nav, $locale, $current, $depth);
        } elseif ($from !== null) {
            $items = $this->fromType($site, $from, $locale, $current);
        }
        $free = $nav->value('items') === 'db' ? $this->fromDatabase($site, (string) $nav->name, $locale, $current, $currentPath, $depth) : null;
        if ($free !== null && $free !== []) {
            return array_merge($items, $free);
        }

        return array_merge($items, $this->fromTheme($site, $nav, $locale, $current));
    }

    /**
     * Brotkrumen (M-07): Startseite, Vorfahren mit Pfad (Ordner übersprungen), die Seite selbst als letzte.
     *
     * @return list<array{label: string, href: string, current: bool}>
     */
    public function breadcrumbs(Entry $entry, string $locale): array
    {
        $site = $entry->site;
        $byId = $this->entriesOf($site)->keyBy('id');
        $trail = [];
        for ($node = $entry; $node !== null; $node = $node->parent_id === null ? null : $byId->get($node->parent_id)) {
            $trail[] = $node;
        }
        $trail = array_reverse($trail);
        $home = $this->homeEntry($site, $locale);
        if ($home !== null && $trail[0]->id !== $home->id) {
            array_unshift($trail, $home);
        }
        $crumbs = [];
        foreach ($trail as $node) {
            $isCurrent = $node->id === $entry->id;
            $type = $node->pageType();
            if (! $isCurrent && ($type === null || ! $type->hasSlug()) && $node->id !== $home?->id) {
                continue;
            }
            $version = $this->pages->versionOf($node, $locale, ! $isCurrent) ?? $this->pages->versionOf($node, $locale, false);
            $url = $this->pages->urlOf($node, $locale, ! $isCurrent) ?? $this->pages->urlOf($node, $locale, false);
            if ($version === null || $url === null) {
                continue;
            }
            $crumbs[] = ['label' => $version->title, 'href' => $url, 'current' => $isCurrent];
        }

        return count($crumbs) > 1 ? $crumbs : [];
    }

    public function forget(): void
    {
        $this->entries = [];
    }

    /** @return list<Item> */
    private function fromTree(Site $site, Theme $theme, Definition $nav, string $locale, ?Entry $current, int $depth): array
    {
        $entries = $this->entriesOf($site);
        $byParent = $entries->groupBy(static fn (Entry $e): string => (string) ($e->parent_id ?? 0));
        $ancestors = $this->ancestorIds($entries, $current);
        $start = max(1, (int) ($nav->value('start') ?? 1));
        $only = $nav->property('only')?->refs() ?? [];
        $exclude = $nav->property('exclude')?->refs() ?? [];
        $schema = ThemeSchema::fromTheme($theme);
        $renderer = app(EntryRenderer::class);
        // Startebene: die Kinder der aktiven Seite auf Ebene start − 1
        $rootId = null;
        if ($start > 1) {
            $path = array_reverse($ancestors);
            if ($current !== null) {
                $path[] = $current->id;
            }
            $rootId = $path[$start - 2] ?? null;
            if ($rootId === null) {
                return [];
            }
        }

        return $this->treeLevel($byParent, $rootId, 1, ['locale' => $locale, 'current' => $current, 'ancestors' => $ancestors, 'depth' => $depth, 'only' => $only, 'exclude' => $exclude, 'schema' => $schema, 'renderer' => $renderer]);
    }

    /**
     * Eine Ebene des Seitenbaums als Menüeinträge — Ordner heben ihre Kinder auf diese Ebene.
     *
     * @param  Collection<string, Collection<int, Entry>>  $byParent
     * @param  array{locale: string, current: Entry|null, ancestors: list<int>, depth: int, only: list<string>, exclude: list<string>, schema: ThemeSchema, renderer: EntryRenderer}  $ctx
     * @return list<Item>
     */
    private function treeLevel(Collection $byParent, ?int $parentId, int $level, array $ctx): array
    {
        $out = [];
        foreach ($byParent->get((string) ($parentId ?? 0), new Collection) as $entry) {
            $type = $entry->pageType();
            if ($type === null) {
                continue;
            }
            if ($type === PageType::Folder) {
                $out = array_merge($out, $this->treeLevel($byParent, $entry->id, $level, $ctx));

                continue;
            }
            // TB 13 Scheibe 2: geschützte Seiten nur für Mitglieder — Gäste sehen sie nicht (ihre Seiten kommen aus dem Cache, Titel bleiben verborgen)
            if ($type->isError() || $type === PageType::Feed || (bool) $entry->setting('expert.hide_in_menu', false) || ! $this->pages->isVisible($entry) || ! app(PageAccess::class)->mayView($entry)) {
                continue;
            }
            if ($ctx['only'] !== [] || $ctx['exclude'] !== []) {
                $template = $ctx['renderer']->templateFor($entry, $ctx['schema']);
                if (in_array($template, $ctx['exclude'], true) || ($ctx['only'] !== [] && ! in_array($template, $ctx['only'], true))) {
                    continue;
                }
            }
            $version = $this->pages->versionOf($entry, $ctx['locale']);
            $href = $type === PageType::Redirect ? $this->redirectTarget($entry, $ctx['locale']) : $this->pages->urlOf($entry, $ctx['locale']);
            if ($version === null || $href === null) {
                continue;
            }
            $children = $level < $ctx['depth'] ? $this->treeLevel($byParent, $entry->id, $level + 1, $ctx) : [];
            $out[] = $this->item($version->title, $href, $entry->id, $ctx['current'], $ctx['ancestors'], false, $children);
        }

        return $out;
    }

    /** @return list<Item> */
    private function fromType(Site $site, string $type, string $locale, ?Entry $current): array
    {
        $out = [];
        foreach ($this->entriesOf($site)->where('type', $type) as $entry) {
            $version = $this->pages->versionOf($entry, $locale);
            $href = $this->pages->urlOf($entry, $locale);
            if ($version !== null && $href !== null && $this->pages->isVisible($entry) && app(PageAccess::class)->mayView($entry)) {
                $out[] = $this->item($version->title, $href, $entry->id, $current, [], false, []);
            }
        }

        return $out;
    }

    /**
     * Freie Einträge der Station D — null, wenn das Menü keine hat (dann gelten die Einträge des Themes).
     *
     * @return list<Item>|null
     */
    private function fromDatabase(Site $site, string $nav, string $locale, ?Entry $current, ?string $currentPath, int $depth): ?array
    {
        $rows = NavigationItem::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('nav', $nav)->orderBy('position')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $byParent = $rows->groupBy(static fn (NavigationItem $i): string => (string) ($i->parent_id ?? 0));
        $entries = $this->entriesOf($site)->keyBy('id');
        $ancestors = $this->ancestorIds($this->entriesOf($site), $current);
        $chain = $site->fallbackChain($locale);

        return $this->databaseLevel($byParent, null, 1, ['entries' => $entries, 'locale' => $locale, 'chain' => $chain, 'current' => $current, 'path' => $currentPath, 'ancestors' => $ancestors, 'depth' => $depth]);
    }

    /**
     * @param  Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, NavigationItem>>  $byParent
     * @param  array{entries: Collection<int, Entry>, locale: string, chain: list<string>, current: Entry|null, path: string|null, ancestors: list<int>, depth: int}  $ctx
     * @return list<Item>
     */
    private function databaseLevel(Collection $byParent, ?int $parentId, int $level, array $ctx): array
    {
        $out = [];
        foreach ($byParent->get((string) ($parentId ?? 0), new Collection) as $row) {
            if (! $row->visible) {
                continue;
            }
            $label = $row->labelFor($ctx['chain']);
            $entryId = null;
            if ($row->entry_id !== null) {
                $entry = $ctx['entries']->get($row->entry_id);
                $href = $entry === null || ! $this->pages->isVisible($entry) || ! app(PageAccess::class)->mayView($entry) ? null : $this->pages->urlOf($entry, $ctx['locale']);
                if ($entry === null || $href === null) {
                    continue;
                }
                $label ??= $this->pages->versionOf($entry, $ctx['locale'])?->title;
                $entryId = $entry->id;
            } else {
                $href = (string) $row->url;
            }
            if ($label === null || $href === '') {
                continue;
            }
            $children = $level < $ctx['depth'] ? $this->databaseLevel($byParent, $row->id, $level + 1, $ctx) : [];
            $item = $this->item($label, $href, $entryId, $ctx['current'], $ctx['ancestors'], $row->new_window, $children);
            if ($entryId === null && $ctx['path'] !== null && rtrim($href, '/') === rtrim($ctx['path'], '/')) {
                $item['current'] = true;
                $item['active'] = true;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Feste Einträge im Theme: «item "Kontakt" -> kontakt» zeigt auf die Seite mit diesem Slug (Standardsprache),
     * «-> /pfad» oder «-> https://…» bleiben, wie sie sind.
     *
     * @return list<Item>
     */
    private function fromTheme(Site $site, Definition $nav, string $locale, ?Entry $current): array
    {
        $out = [];
        foreach ($nav->childrenOf('item') as $item) {
            $target = $item->arrowTarget();
            if ($target === null) {
                continue;
            }
            if ($target->type !== Value::REF) {
                $out[] = $this->item((string) $item->name, $target->text(), null, $current, [], str_starts_with($target->text(), 'http'), []);

                continue;
            }
            $entry = $this->entryBySlug($site, $target->text());
            $href = $entry === null ? null : $this->pages->urlOf($entry, $locale);
            if ($entry !== null && $href !== null) {
                $out[] = $this->item((string) $item->name, $href, $entry->id, $current, $this->ancestorIds($this->entriesOf($site), $current), false, []);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ancestors
     * @param  list<Item>  $children
     * @return Item
     */
    private function item(string $label, string $href, ?int $entryId, ?Entry $current, array $ancestors, bool $newWindow, array $children): array
    {
        $isCurrent = $entryId !== null && $current !== null && $entryId === $current->id;
        $childActive = false;
        foreach ($children as $child) {
            $childActive = $childActive || $child['current'] || $child['active'];
        }

        return ['label' => $label, 'href' => $href, 'current' => $isCurrent, 'active' => $isCurrent || ($entryId !== null && in_array($entryId, $ancestors, true)) || $childActive,
            'new_window' => $newWindow, 'entry_id' => $entryId, 'children' => $children];
    }

    private function redirectTarget(Entry $entry, string $locale): ?string
    {
        $to = $entry->redirect_to;
        if (! is_array($to)) {
            return null;
        }
        if (($to['kind'] ?? 'internal') === 'external') {
            return $entry->externalRedirect($locale);
        }
        $target = is_numeric($to['entry_id'] ?? null) ? $this->entriesOf($entry->site)->firstWhere('id', (int) $to['entry_id']) : null;

        return $target === null ? null : $this->pages->urlOf($target, $locale);
    }

    private function entryBySlug(Site $site, string $slug): ?Entry
    {
        foreach ($this->entriesOf($site) as $entry) {
            if ($entry->pageType() !== null && $this->pages->versionOf($entry, $site->default_locale, false)?->slug === $slug) {
                return $entry;
            }
        }

        return null;
    }

    private function homeEntry(Site $site, string $locale): ?Entry
    {
        foreach ($this->entriesOf($site) as $entry) {
            if ($entry->pageType() !== null && $this->pages->isHome($entry, $locale)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Entry>  $entries
     * @return list<int> Vorfahren der aktuellen Seite (nächster zuerst)
     */
    private function ancestorIds(Collection $entries, ?Entry $current): array
    {
        if ($current === null) {
            return [];
        }
        $byId = $entries->keyBy('id');
        $ids = [];
        for ($id = $current->parent_id; $id !== null && ! in_array($id, $ids, true); $id = $byId->get($id)?->parent_id) {
            $ids[] = $id;
        }

        return $ids;
    }

    /** @return Collection<int, Entry> */
    private function entriesOf(Site $site): Collection
    {
        return $this->entries[$site->id] ??= $this->pages->entries($site);
    }
}
