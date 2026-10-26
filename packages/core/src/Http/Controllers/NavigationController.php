<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\NavigationItem;
use Basalto\Core\Models\Site;
use Basalto\Core\Navigation\Navigation;
use Basalto\Core\Navigation\NavigationItems;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Lava\Structure\Definition;
use Basalto\Lava\Structure\Printer;
use Basalto\Lava\Theme\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Station D — Navigation (M-07, Mockup 15): die Menüs des Themes mit Quelle, Tiefe, Filtern und Code; freie Menüs
 * (`items: db`) mit Einträgen je Sprache anlegen, ändern, sortieren, verschachteln und löschen. Speichern ist live.
 */
final class NavigationController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeLoader $themes, private readonly Navigation $navigation, private readonly Pages $pages, private readonly NavigationItems $editor) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $theme = $this->themes->forSite($site);
        $defs = $theme->document->all('nav');
        $handle = (string) $request->query('nav', '');
        $active = null;
        foreach ($defs as $def) {
            if ($def->name === $handle) {
                $active = $def;
            }
        }
        $active ??= $defs[0] ?? null;
        $locale = in_array($request->query('locale'), $site->localeCodes(), true) ? (string) $request->query('locale') : $site->default_locale;
        $navs = array_map(fn (Definition $def): array => $this->describe($site, $def), $defs);

        return Inertia::render('core::Navigation/Index', [
            'navs' => $navs,
            'nav' => $active?->name,
            'locale' => $locale,
            'locales' => $site->locales->map(static fn ($l): array => ['locale' => $l->locale, 'is_default' => (bool) $l->is_default])->values()->all(),
            'items' => $active === null ? [] : $this->items($site, $theme, $active, $locale),
            'preview' => $active === null ? [] : $this->preview($this->navigation->items($site, $theme, $active, $locale)),
            'pages' => $this->pageOptions($site),
            'max_depth' => $active === null ? 1 : max(1, (int) ($active->value('depth') ?? 1)),
            'urls' => [
                'index' => route('basalto.setup.navigation.index'),
                'store' => $active !== null && NavigationItems::editable($active) ? route('basalto.setup.navigation.store', ['nav' => $active->name]) : null,
                'order' => $active !== null && NavigationItems::editable($active) ? route('basalto.setup.navigation.order', ['nav' => $active->name]) : null,
            ],
            'can_edit' => $this->canEdit($site),
            'status' => session('status'),
        ]);
    }

    public function store(Request $request, string $nav): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless($this->canEdit($site), 403);
        $item = $this->editor->create($request, $site, $nav);

        return redirect()->route('basalto.setup.navigation.index', ['nav' => $nav, 'locale' => $request->input('locale')])->with('status', __('Eintrag «:label» angelegt.', ['label' => $this->labelOf($site, $item)]));
    }

    public function update(Request $request, NavigationItem $item): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless($this->canEdit($site), 403);
        $this->editor->update($request, $site, $item);

        return back()->with('status', __('Eintrag «:label» gespeichert.', ['label' => $this->labelOf($site, $item)]));
    }

    /** Löschen — Untereinträge rücken an seine Stelle. */
    public function destroy(NavigationItem $item): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless($this->canEdit($site), 403);
        $label = $this->labelOf($site, $item);
        $this->editor->delete($item);

        return back()->with('status', __('Eintrag «:label» gelöscht.', ['label' => $label]));
    }

    /** Reihenfolge und Verschachtelung aus der Liste `[{id, parent_id}]` — Tiefe höchstens die des Menüs. */
    public function order(Request $request, string $nav): RedirectResponse
    {
        $site = $this->current->require();
        abort_unless($this->canEdit($site), 403);
        $def = $this->editor->editableNav($site, $nav);
        $maxDepth = max(1, (int) ($def->value('depth') ?? 1));
        $request->validate(['items' => ['required', 'array'], 'items.*.id' => ['required', 'integer'], 'items.*.parent_id' => ['nullable', 'integer']]);
        $rows = array_values((array) $request->input('items'));
        $existing = NavigationItem::query()->where('nav', $nav)->pluck('id')->all();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        sort($existing);
        $sorted = $ids;
        sort($sorted);
        if ($sorted !== $existing) {
            throw ValidationException::withMessages(['items' => __('Die Liste muss genau die Einträge dieses Menüs enthalten.')]);
        }
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) $row['id']] = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        }
        foreach ($parents as $id => $parent) {
            $depth = 1;
            $seen = [$id];
            for ($p = $parent; $p !== null; $p = $parents[$p] ?? null) {
                if (! array_key_exists($p, $parents) || in_array($p, $seen, true)) {
                    throw ValidationException::withMessages(['items' => __('Ungültige Verschachtelung.')]);
                }
                $seen[] = $p;
                $depth++;
            }
            if ($depth > $maxDepth) {
                throw ValidationException::withMessages(['items' => trans_choice('Dieses Menü hat höchstens :count Ebene (nav :nav, depth: :count).|Dieses Menü hat höchstens :count Ebenen (nav :nav, depth: :count).', $maxDepth, ['count' => $maxDepth, 'nav' => $nav])]);
            }
        }
        DB::transaction(static function () use ($rows, $parents): void {
            $positions = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $key = (string) ($parents[$id] ?? 0);
                $positions[$key] = ($positions[$key] ?? 0) + 1;
                NavigationItem::query()->whereKey($id)->update(['parent_id' => $parents[$id], 'position' => $positions[$key]]);
            }
        });

        return back()->with('status', __('Reihenfolge gespeichert.'));
    }

    private function canEdit(Site $site): bool
    {
        return Gate::allows('create', new Entry(['site_id' => $site->id]));
    }

    /** @return array<string, mixed> */
    private function describe(Site $site, Definition $def): array
    {
        $from = $def->value('from');
        $editable = NavigationItems::editable($def);
        $count = $editable ? NavigationItem::query()->where('nav', (string) $def->name)->count() : 0;
        [$source, $sourceLabel] = match (true) {
            $editable => ['db', $count > 0 ? __('frei · :count', ['count' => $count]) : __('frei · noch leer')],
            $from === 'pages' => ['pages', __('aus Seitenbaum')],
            $from !== null => ['type', __('Typ-Liste «:type»', ['type' => $from])],
            default => ['theme', __('aus dem Theme')],
        };

        return [
            'handle' => (string) $def->name,
            'label' => $def->value('label') ?? ucfirst((string) $def->name),
            'source' => $source,
            'source_label' => $sourceLabel,
            'editable' => $editable,
            'depth' => max(1, (int) ($def->value('depth') ?? 1)),
            'start' => max(1, (int) ($def->value('start') ?? 1)),
            'exclude' => $def->property('exclude')?->refs() ?? [],
            'only' => $def->property('only')?->refs() ?? [],
            'count' => $count,
            'code' => rtrim((new Printer)->printDefinition($def)),
            'line' => $def->line > 0 ? $def->line : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(Site $site, Theme $theme, Definition $def, string $locale): array
    {
        $out = [];
        if (! NavigationItems::editable($def)) {
            $walk = function (array $items, int $depth) use (&$walk, &$out, $locale): void {
                foreach ($items as $item) {
                    $out[] = ['id' => null, 'parent_id' => null, 'depth' => $depth, 'label' => [$locale => $item['label']], 'label_effective' => $item['label'], 'label_fallback' => false,
                        'target' => ['kind' => $item['entry_id'] !== null ? 'entry' : 'url', 'entry_id' => $item['entry_id'], 'url' => $item['entry_id'] === null ? $item['href'] : null, 'title' => $item['label'], 'path' => $item['href']],
                        'visible' => true, 'new_window' => $item['new_window'], 'live' => true, 'hint' => null, 'urls' => null];
                    $walk($item['children'], $depth + 1);
                }
            };
            $walk($this->navigation->items($site, $theme, $def, $locale), 0);

            return $out;
        }
        $rows = NavigationItem::query()->where('nav', (string) $def->name)->with('entry')->orderBy('position')->orderBy('id')->get();
        $byParent = $rows->groupBy(static fn (NavigationItem $i): string => (string) ($i->parent_id ?? 0));
        $chain = $site->fallbackChain($locale);
        $walk = function (?int $parentId, int $depth) use (&$walk, &$out, $byParent, $locale, $chain): void {
            foreach ($byParent->get((string) ($parentId ?? 0), collect()) as $row) {
                $entry = $row->entry;
                $title = $entry === null ? null : ($this->pages->versionOf($entry, $locale, false)?->title);
                $live = $entry === null ? $row->entry_id === null : ($this->pages->urlOf($entry, $locale) !== null && $this->pages->isVisible($entry));
                $own = trim((string) ($row->label[$locale] ?? ''));
                $effective = $row->labelFor($chain) ?? $title ?? '';
                $out[] = [
                    'id' => $row->id, 'parent_id' => $row->parent_id, 'depth' => $depth, 'label' => $row->label ?? [], 'label_effective' => $effective, 'label_fallback' => $own === '',
                    'target' => ['kind' => $row->entry_id !== null ? 'entry' : 'url', 'entry_id' => $row->entry_id, 'url' => $row->url, 'title' => $title,
                        'path' => $entry === null ? $row->url : $this->pages->urlOf($entry, $locale, false)],
                    'visible' => $row->visible, 'new_window' => $row->new_window, 'live' => $live,
                    'hint' => match (true) {
                        $row->entry_id !== null && $entry === null => __('Seite gelöscht — der Eintrag erscheint nicht'),
                        ! $live => __('Seite nicht veröffentlicht — erscheint, sobald sie live ist'),
                        ! $row->visible => 'ausgeblendet',
                        default => null,
                    },
                    'urls' => ['update' => route('basalto.setup.navigation.update', $row), 'delete' => route('basalto.setup.navigation.destroy', $row)],
                ];
                $walk($row->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function preview(array $items): array
    {
        return array_map(fn (array $i): array => ['label' => $i['label'], 'href' => $i['href'], 'active' => (bool) $i['active'], 'children' => $this->preview($i['children'])], $items);
    }

    /** @return list<array{id: int, title: string, path: string|null, depth: int, live: bool}> */
    private function pageOptions(Site $site): array
    {
        $entries = $this->pages->entries($site)->filter(static fn (Entry $e): bool => in_array($e->pageType(), [PageType::Page, PageType::Redirect], true) || $e->pageType() === PageType::Folder);
        $byParent = $entries->groupBy(static fn (Entry $e): string => (string) ($e->parent_id ?? 0));
        $out = [];
        $walk = function (?int $parentId, int $depth) use (&$walk, &$out, $byParent, $site): void {
            foreach ($byParent->get((string) ($parentId ?? 0), collect()) as $entry) {
                if ($entry->pageType() !== PageType::Folder) {
                    $out[] = ['id' => $entry->id, 'title' => $this->pages->versionOf($entry, $site->default_locale, false)->title ?? __('(ohne Namen)'),
                        'path' => $this->pages->urlOf($entry, $site->default_locale, false), 'depth' => $depth, 'live' => $this->pages->urlOf($entry, $site->default_locale) !== null];
                }
                $walk($entry->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $out;
    }

    private function labelOf(Site $site, NavigationItem $item): string
    {
        $entry = $item->entry_id === null ? null : Entry::query()->find($item->entry_id);

        return $item->labelFor($site->fallbackChain($site->default_locale)) ?? ($entry === null ? '' : ($this->pages->versionOf($entry, $site->default_locale, false)->title ?? ''));
    }
}
