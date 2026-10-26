<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Content\Completeness;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\PageAccess;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\Translations;
use Basalto\Core\Content\WorkflowException;
use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Http\Requests\PageRequest;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\NotFoundHit;
use Basalto\Core\Models\Redirect;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Seitenbaum der Site (M-01, Mockup 28): Baum mit Typen, Pfaden und Statuspunkten je Sprache, Weiterleitungen,
 * Papierkorb; Seite anlegen mit Typwahl, Einstellungen in den Reitern des Typs, verschieben, löschen, wiederherstellen.
 * Sprachunabhängige Einstellungen wirken sofort (`entries.settings`); Name, Slug und Metadaten je Sprache werden als
 * neue Entwurfsfassung gespeichert und gehen mit der Veröffentlichung live.
 */
final class PageController
{
    private const ENTRY_PLACEHOLDER = '__basalto_entry__';

    private const VERSION_PLACEHOLDER = '__basalto_version__';

    public function __construct(private readonly CurrentSite $current, private readonly Pages $pages, private readonly ThemeLoader $themes, private readonly Dates $dates) {}

    public function index(Request $request): Response
    {
        $site = $this->current->require();
        $this->pages->forget();
        $site->loadMissing('locales');
        $entries = Entry::query()->with('versions')->whereIn('type', PageType::values())->orderBy('position')->orderBy('id')->get()
            ->each(static fn (Entry $entry) => $entry->setRelation('site', $site));
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $authorIds = $entries->flatMap(static fn (Entry $e) => $e->versions->pluck('author_id'))->filter()->unique()->values();
        $names = $userModel::query()->whereIn('id', $authorIds)->pluck('name', 'id');
        $byParent = $entries->groupBy(static fn (Entry $e): string => (string) ($e->parent_id ?? 0));
        $filters = ['status' => (string) $request->query('status', ''), 'locale' => (string) $request->query('locale', ''), 'q' => trim((string) $request->query('q', ''))];
        $filtering = $filters['status'] !== '' || $filters['locale'] !== '' || $filters['q'] !== '';
        $rows = [];
        $urls = $this->rowUrlTemplates($site);
        $tree = $this->pages->treePaths($site, $entries, $site->default_locale);
        // Eine Instanz für alle Zeilen — sie hält das Theme-Schema; je Zeile neu hiesse je Zeile neu kompilieren (N-03)
        $translations = app(Translations::class);
        $context = ['urls' => $urls, 'tree' => $tree, 'translations' => $translations, 'relative' => $this->dates->relativeFormatter(), 'locales' => $site->localeCodes()];
        $walk = function (?int $parentId, int $depth) use (&$walk, &$rows, $byParent, $site, $names, $filters, $filtering, $context): void {
            foreach ($byParent->get((string) ($parentId ?? 0), new Collection) as $entry) {
                $row = $this->row($site, $entry, $depth, $byParent->has((string) $entry->id), $names, $context);
                $row['match'] = ! $filtering || $this->matches($row, $entry, $filters);
                $row['visible'] = $row['match'];
                $rows[] = $row;
                $walk($entry->id, $depth + 1);
            }
        };
        $walk(null, 0);
        // Vorfahren eines Treffers bleiben sichtbar (gedimmt), damit der Baum lesbar bleibt
        $byId = [];
        foreach ($rows as $i => $row) {
            $byId[$row['id']] = $i;
        }
        foreach ($rows as $row) {
            if (! $row['match']) {
                continue;
            }
            for ($parentId = $row['parent_id']; $parentId !== null && isset($byId[$parentId]); $parentId = $rows[$byId[$parentId]]['parent_id']) {
                $rows[$byId[$parentId]]['visible'] = true;
            }
        }
        $redirects = Redirect::query()->orderByDesc('id')->limit(500)->get()->map(fn (Redirect $r): array => [
            'id' => $r->id, 'locale' => $r->locale, 'from' => $r->from_path, 'to' => $r->to_path, 'status' => $r->status, 'source' => $r->source, 'hits' => $r->hits, 'created_at' => $this->dates->dateTime($r->created_at),
            'last_hit_at' => $this->dates->relative($r->last_hit_at),
            'update_url' => route('basalto.pages.redirects.update', $r), 'delete_url' => route('basalto.pages.redirects.destroy', $r),
        ])->all();
        $notFound = NotFoundHit::query()->orderByDesc('hits')->orderByDesc('last_seen_at')->limit(200)->get()->map(fn (NotFoundHit $h): array => [
            'id' => $h->id, 'path' => $h->path, 'locale' => $h->locale, 'hits' => $h->hits, 'referrer' => $h->referrer,
            'first_seen_at' => $this->dates->dateTime($h->first_seen_at), 'last_seen_at' => $this->dates->relative($h->last_seen_at),
            'dismiss_url' => route('basalto.pages.not_found.destroy', $h),
        ])->all();
        // TB 29: Unterseiten, die mit ihrer Elternseite gelöscht wurden, stehen nicht einzeln da — sie kommen mit ihr zurück
        $trashed = Entry::query()->onlyTrashed()->orderByDesc('deleted_at')->get();
        $byId = $trashed->keyBy('id');
        $withParent = static fn (Entry $e): bool => $e->parent_id !== null && ($parent = $byId->get($e->parent_id)) !== null && $parent->deleted_at?->equalTo($e->deleted_at);
        $trash = $trashed->reject($withParent)->map(fn (Entry $e): array => [
            'id' => $e->id, 'title' => $e->latestVersion($site->default_locale)->title ?? __('(ohne Namen)'), 'type_label' => $e->pageType()?->label() ?? $e->type,
            'children' => self::descendants($e, true)->filter(static fn (Entry $c): bool => $c->deleted_at?->equalTo($e->deleted_at) ?? false)->count(),
            'deleted_at' => $this->dates->dateTime($e->deleted_at), 'restore_url' => route('basalto.pages.restore', $e->id),
        ])->values()->all();

        return Inertia::render('core::Pages/Index', [
            'tree' => $rows,
            'redirects' => $redirects,
            'not_found' => $notFound,
            'import_errors' => session('import_errors', []),
            'trash' => $trash,
            'types' => $this->typeOptions(),
            'locales' => $this->localeOptions($site),
            'filters' => $filters,
            'status_options' => array_map(static fn (EntryStatus $s): array => ['value' => $s->value, 'label' => $s->label()], EntryStatus::cases()),
            'counts' => ['tree' => $filtering ? count(array_filter($rows, static fn (array $r): bool => $r['match'])) : count($rows), 'redirects' => count($redirects), 'not_found' => count($notFound), 'trash' => count($trash)],
            'can_edit' => Gate::allows('create', new Entry(['site_id' => $site->id])),
            'urls' => ['create' => route('basalto.pages.create'), 'bulk' => route('basalto.pages.bulk'),
                'redirect_store' => route('basalto.pages.redirects.store'), 'redirect_import' => route('basalto.pages.redirects.import')],
            'can_publish' => Gate::allows('publish', new Entry(['site_id' => $site->id])),
        ]);
    }

    public function create(Request $request): Response
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));
        $type = PageType::tryFrom((string) $request->query('type', PageType::Page->value)) ?? PageType::Page;
        $parentId = is_numeric($request->query('parent')) ? (int) $request->query('parent') : null;

        return $this->form($site, null, $type, $parentId);
    }

    public function store(PageRequest $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));
        $type = $request->type();
        $parentId = is_numeric($request->input('parent_id')) ? (int) $request->input('parent_id') : null;
        $position = Entry::query()->where('parent_id', $parentId)->count();
        $entry = Entry::query()->create([
            'site_id' => $site->id, 'type' => $type->value, 'parent_id' => $parentId, 'position' => $position,
            'settings' => $this->settingsFrom($request, $type), 'redirect_to' => $this->redirectFrom($request, $type),
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);
        $this->saveVersions($site, $entry, $request);
        $this->saveHome($site, $entry, $request);
        $this->pages->forget();

        return $this->done($request, $entry, __(':type «:name» angelegt.', ['type' => $type->label(), 'name' => (string) $request->input('name.'.$site->default_locale)]));
    }

    public function edit(Entry $entry): Response
    {
        Gate::authorize('view', $entry);
        $type = $entry->pageType();
        abort_if($type === null, 404);

        return $this->form($entry->site, $entry, $type, $entry->parent_id);
    }

    public function update(PageRequest $request, Entry $entry): RedirectResponse
    {
        Gate::authorize('update', $entry);
        // Einstellungen und Ort im Baum sind Struktur — nur, wer die Quellsprache bearbeiten darf (CR-005, R-07)
        app(Access::class)->authorizeLocale($request->user(), $entry->site, $entry->site->default_locale);
        $site = $entry->site;
        $type = $request->type();
        $parentId = is_numeric($request->input('parent_id')) ? (int) $request->input('parent_id') : null;
        $entry->type = $type->value;
        $entry->settings = $this->settingsFrom($request, $type);
        $entry->redirect_to = $this->redirectFrom($request, $type);
        $this->pages->trackPaths($site, function () use ($entry, $site, $request, $parentId): void {
            $entry->save();
            if ($parentId !== $entry->parent_id) {
                $this->pages->move($entry, $parentId, PHP_INT_MAX);
            }
            $this->saveVersions($site, $entry, $request);
        });
        $this->saveHome($site, $entry, $request);
        $this->pages->forget();

        return $this->done($request, $entry, __('Einstellungen gespeichert.'));
    }

    /** Verschieben im Baum (Ziehen oder Knöpfe): neue Elternseite und Position; Umleitungen entstehen automatisch. */
    public function move(Request $request, Entry $entry): RedirectResponse
    {
        Gate::authorize('update', $entry);
        app(Access::class)->authorizeLocale($request->user(), $entry->site, $entry->site->default_locale);
        $validated = $request->validate(['parent_id' => ['nullable', 'integer'], 'position' => ['required', 'integer', 'min:0']]);
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        try {
            $this->pages->move($entry, $parentId, (int) $validated['position']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['move' => $e->getMessage()]);
        }

        return back()->with('status', __('Seite verschoben.'));
    }

    public function destroy(Entry $entry): RedirectResponse
    {
        Gate::authorize('delete', $entry);
        $title = $entry->latestVersion($entry->site->default_locale)->title ?? '';
        // TB 29: samt allen Unterseiten — sonst zeigten sie auf eine gelöschte Elternseite. Alle tragen denselben
        // Zeitpunkt; daran erkennt «Wiederherstellen», was zusammen zurückkommt
        $children = 0;
        $this->pages->trackPaths($entry->site, static function () use ($entry, &$children): void {
            $descendants = self::descendants($entry, false);
            $children = $descendants->count();
            DB::transaction(static function () use ($entry, $descendants): void {
                $entry->delete();
                foreach ($descendants as $child) {
                    $child->delete();
                }
                Entry::query()->onlyTrashed()->whereKey($descendants->modelKeys())->update(['deleted_at' => $entry->deleted_at]);
            });
        });
        $this->pages->forget();

        return redirect()->route('basalto.pages.index')->with('status', $children === 0
            ? __('«:title» in den Papierkorb gelegt.', ['title' => $title])
            : trans_choice('«:title» mit :count Unterseite in den Papierkorb gelegt.|«:title» mit :count Unterseiten in den Papierkorb gelegt.', $children, ['title' => $title, 'count' => $children]));
    }

    /**
     * Seite kopieren (TB 01 → TB 05): gleicher Typ, gleiche Einstellungen, direkt dahinter; je Sprache die neueste
     * Fassung als Entwurf mit freiem Slug «…-kopie». Unterseiten kommen nicht mit.
     */
    public function copy(Request $request, Entry $entry): RedirectResponse
    {
        Gate::authorize('create', $entry);
        abort_if($entry->pageType() === null, 404);
        $site = $entry->site;
        $copy = DB::transaction(function () use ($entry, $site, $request): Entry {
            Entry::query()->where('parent_id', $entry->parent_id)->where('position', '>', $entry->position)->increment('position');
            $copy = Entry::query()->create(['site_id' => $site->id, 'type' => $entry->type, 'parent_id' => $entry->parent_id, 'position' => $entry->position + 1,
                'settings' => $entry->settings, 'redirect_to' => $entry->redirect_to, 'created_by' => $request->user()?->getAuthIdentifier()]);
            foreach ($site->localeCodes() as $locale) {
                $source = $entry->latestVersion($locale);
                if ($source === null) {
                    continue;
                }
                $slug = $source->slug.'-kopie';
                $path = $this->pages->pathFor($site, $entry->parent_id, $locale, $slug);
                if ($path !== null && $this->pages->entryIdAt($site, $locale, $path, false) !== null) {
                    $slug = $this->pages->freeSlug($site, $entry->parent_id, $locale, $slug);
                }
                $version = $copy->newDraft($locale, ['title' => __(':title (Kopie)', ['title' => $source->title]), 'slug' => $slug] + $source->data, is_numeric($request->user()?->getAuthIdentifier()) ? (int) $request->user()->getAuthIdentifier() : null);
                event(new EntrySaved($copy, $version));
            }

            return $copy;
        });
        $this->pages->forget();

        return redirect()->route('basalto.pages.index')->with('status', __('Kopie «:title» angelegt — als Entwurf.', ['title' => $copy->latestVersion($site->default_locale)->title ?? '']));
    }

    /**
     * Massenaktionen im Seitenbaum: einreichen (neueste Entwürfe aller Sprachen), veröffentlichen (freigegebene oder
     * geplante Fassungen), in den Papierkorb. Was die Regeln nicht erlauben, wird gezählt und genannt, nicht erzwungen.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'], 'action' => ['required', 'in:submit,publish,delete']]);
        $entries = Entry::query()->whereKey($validated['ids'])->whereIn('type', PageType::values())->get();
        $done = 0;
        $skipped = [];
        foreach ($entries as $entry) {
            $title = $entry->latestVersion($site->default_locale)->title ?? '#'.$entry->id;
            try {
                if ($validated['action'] === 'delete') {
                    Gate::authorize('delete', $entry);
                    $this->pages->trackPaths($site, static fn () => $entry->delete());
                    $done++;

                    continue;
                }
                Gate::authorize($validated['action'] === 'submit' ? 'update' : 'publish', $entry);
                if ($validated['action'] === 'submit') {
                    app(Access::class)->authorizeLocale($request->user(), $site, $site->default_locale);
                }
                $any = false;
                foreach ($site->localeCodes() as $locale) {
                    $version = $entry->latestVersion($locale);
                    if ($version === null) {
                        continue;
                    }
                    if ($validated['action'] === 'submit' && $version->status === EntryStatus::Draft) {
                        if (app(Completeness::class)->missing($entry, $version) !== []) {
                            throw new WorkflowException(__('Pflichtfelder fehlen'));
                        }
                        $version->submit(is_numeric($request->user()?->getAuthIdentifier()) ? (int) $request->user()->getAuthIdentifier() : null);
                        $any = true;
                    } elseif ($validated['action'] === 'publish' && in_array($version->status, [EntryStatus::Approved, EntryStatus::Scheduled], true)) {
                        $version->publish();
                        $any = true;
                    }
                }
                $any ? $done++ : $skipped[] = $title;
            } catch (WorkflowException|AuthorizationException $e) {
                $skipped[] = $title;
            }
        }
        $this->pages->forget();
        $status = match ($validated['action']) {
            'submit' => trans_choice(':count Seite eingereicht.|:count Seiten eingereicht.', $done, ['count' => $done]),
            'publish' => trans_choice(':count Seite veröffentlicht.|:count Seiten veröffentlicht.', $done, ['count' => $done]),
            default => trans_choice(':count Seite in den Papierkorb gelegt.|:count Seiten in den Papierkorb gelegt.', $done, ['count' => $done]),
        };
        if ($skipped !== []) {
            $status .= ' '.__('Nicht möglich bei: :pages.', ['pages' => implode(', ', array_map(static fn (string $t): string => '«'.$t.'»', array_slice($skipped, 0, 5))).(count($skipped) > 5 ? ' …' : '')]);
        }

        return redirect()->route('basalto.pages.index')->with('status', $status);
    }

    public function restore(int $id): RedirectResponse
    {
        $site = $this->current->require();
        $entry = Entry::query()->onlyTrashed()->whereKey($id)->first();
        abort_if($entry === null, 404);
        Gate::authorize('delete', $entry);
        $stamp = $entry->deleted_at;
        $descendants = self::descendants($entry, true)->filter(static fn (Entry $child): bool => $child->deleted_at?->equalTo($stamp) ?? false);
        $this->pages->trackPaths($entry->site, static function () use ($entry, $descendants): void {
            DB::transaction(static function () use ($entry, $descendants): void {
                $entry->restore();
                foreach ($descendants as $child) {
                    $child->restore();
                }
            });
        });
        $this->pages->forget();

        return redirect()->route('basalto.pages.index')->with('status', $descendants->isEmpty() ? __('Seite wiederhergestellt.')
            : trans_choice('Seite mit :count Unterseite wiederhergestellt.|Seite mit :count Unterseiten wiederhergestellt.', $descendants->count(), ['count' => $descendants->count()]));
    }

    /**
     * Alle Unterseiten (jede Tiefe) — mit `$trashed` aus dem Papierkorb.
     *
     * @return Collection<int, Entry>
     */
    private static function descendants(Entry $entry, bool $trashed): Collection
    {
        $all = new Collection;
        $parents = [$entry->id];
        while ($parents !== []) {
            $query = Entry::query()->whereIn('parent_id', $parents);
            $children = $trashed ? $query->onlyTrashed()->get() : $query->get();
            $all = $all->merge($children);
            $parents = $children->modelKeys();
        }

        return $all;
    }

    private function form(Site $site, ?Entry $entry, PageType $type, ?int $parentId): Response
    {
        $locales = $site->localeCodes();
        $form = ['type' => $type->value, 'name' => [], 'parent_id' => $parentId,
            'routing' => ['slugs' => [], 'priority' => 0, 'redirect_on_slug_change' => true, 'home' => []],
            'meta' => ['robots' => 'index, follow'], 'image' => ['media_id' => null, 'focus_x' => 0.5, 'focus_y' => 0.5, 'alt' => ''],
            'canonical' => ['mode' => 'auto', 'url' => '', 'keep_query' => ''], 'access' => ['protected' => false, 'children' => true],
            'layout' => ['mode' => 'inherit', 'template' => '', 'children' => 'inherit'], 'cache' => ['page_cache' => true, 'shared_max_age' => (int) $site->setting('cache.shared_max_age', 0), 'private_max_age' => (int) $site->setting('cache.private_max_age', 0), 'always_shared' => false],
            'rights' => ['owner_id' => null, 'roles' => []], 'expert' => ['css_class' => '', 'in_sitemap' => true, 'in_search' => true, 'hide_in_menu' => false, 'mount' => null],
            'keyboard' => ['shortcut' => ''], 'publication' => ['visible_from' => '', 'visible_until' => ''],
            'feed' => ['type' => PageType::Page->value, 'limit' => 20, 'format' => 'rss'], 'redirect' => ['kind' => 'internal', 'entry_id' => null, 'url' => '', 'urls' => (object) [], 'status' => 302],
        ];
        foreach ($locales as $locale) {
            $form['name'][$locale] = '';
            $form['routing']['slugs'][$locale] = '';
            $form['routing']['home'][$locale] = false;
            $form['meta'][$locale] = ['title' => '', 'description' => ''];
        }
        $page = null;
        $redirectsHere = [];
        if ($entry !== null) {
            $settings = is_array($entry->settings) ? $entry->settings : [];
            foreach (['routing', 'meta', 'image', 'canonical', 'access', 'layout', 'cache', 'rights', 'expert', 'keyboard', 'publication', 'feed'] as $tab) {
                if (is_array($settings[$tab] ?? null)) {
                    $form[$tab] = array_replace($form[$tab], $settings[$tab]);
                }
            }
            $form['routing']['home'] = [];
            foreach ($locales as $locale) {
                $form['routing']['home'][$locale] = (int) $site->setting('home.'.$locale) === $entry->id;
            }
            if (is_array($entry->redirect_to)) {
                $form['redirect'] = array_replace($form['redirect'], $entry->redirect_to);
                $form['redirect']['urls'] = (object) (is_array($entry->redirect_to['urls'] ?? null) ? $entry->redirect_to['urls'] : []);
            }
            $paths = [];
            $status = [];
            $numbers = [];
            foreach ($locales as $locale) {
                $latest = $entry->latestVersion($locale);
                $form['name'][$locale] = $latest->title ?? '';
                $form['routing']['slugs'][$locale] = $latest->slug ?? '';
                $versionMeta = is_array($latest?->data['settings']['meta'] ?? null) ? $latest->data['settings']['meta'] : [];
                $form['meta'][$locale] = ['title' => (string) ($versionMeta['title'] ?? ''), 'description' => (string) ($versionMeta['description'] ?? '')];
                $paths[$locale] = $this->pages->urlOf($entry, $locale, false);
                $status[$locale] = $latest?->status->value;
                $numbers[$locale] = $latest?->number;
            }
            $latestDefault = $entry->latestVersion($site->default_locale);
            $page = ['id' => $entry->id, 'type' => $entry->type, 'uuid' => $entry->uuid, 'title' => $latestDefault->title ?? __('(ohne Namen)'),
                'paths' => $paths, 'status' => $status, 'versions' => $numbers, 'created_at' => $this->dates->dateTime($entry->created_at),
                'content_url' => $type->hasContent() ? route('basalto.entries.edit', [$entry, $site->default_locale]) : null,
                'preview_url' => $latestDefault === null ? null : route('basalto.entries.preview', [$entry, $latestDefault]),
                'published_url' => $this->pages->urlOf($entry, $site->default_locale, true)];
            $redirectsHere = Redirect::query()->where('to_path', $this->pages->urlOf($entry, $site->default_locale, true) ?? '')->orderBy('from_path')->get()
                ->map(static fn (Redirect $r): array => ['from' => $r->from_path, 'to' => $r->to_path, 'status' => $r->status, 'source' => $r->source, 'hits' => $r->hits])->all();
        }
        $entries = Entry::query()->with('versions')->orderBy('position')->orderBy('id')->get();
        $parents = [];
        $targets = [];
        foreach ($entries as $candidate) {
            $candidateType = $candidate->pageType();
            if ($candidateType === null || $candidate->id === $entry?->id) {
                continue;
            }
            $paths = [];
            $basePaths = [];
            foreach ($locales as $locale) {
                $paths[$locale] = $this->pages->urlOf($candidate, $locale, false);
                $base = $this->pages->pathFor($site, $candidate->id, $locale, '', false);
                $basePaths[$locale] = $base === null ? null : rtrim($base, '/');
            }
            $option = ['id' => $candidate->id, 'parent_id' => $candidate->parent_id, 'title' => $candidate->versions->where('locale', $site->default_locale)->sortByDesc('number')->first()->title ?? __('(ohne Namen)'),
                'path' => $paths[$site->default_locale] ?? null, 'paths' => $paths, 'base_paths' => $basePaths, 'type' => $candidate->type, 'depth' => $this->depthOf($candidate, $entries)];
            if (in_array($candidateType, [PageType::Page, PageType::Folder], true)) {
                $parents[] = $option;
            }
            if ($candidateType->hasSlug() && $candidateType !== PageType::Redirect) {
                $targets[] = $option;
            }
        }
        $suffix = '';
        try {
            $publish = $this->themes->forSite($site)->document->find('publish');
            $seo = $publish?->childrenOf('seo')[0] ?? $publish?->property('seo')?->block;
            $suffix = str_replace('{site}', (string) $site->name, (string) ($seo?->value('title_suffix') ?? ''));
        } catch (ThemeException) {
        }

        $primary = $site->domains->firstWhere('is_primary', true) ?? $site->domains->first();

        return Inertia::render('core::Pages/Form', [
            'page' => $page,
            'type' => $type->value,
            'type_label' => $type->label(),
            'tabs' => $type->tabs(),
            'form' => $form,
            'options' => [
                'types' => $this->typeOptions(),
                'parents' => $parents,
                'targets' => $targets,
                'templates' => PageRequest::templatesFor($site, $type),
                'content_types' => PageRequest::contentTypesFor($site),
                'media' => Media::query()->latest('id')->limit(100)->get()->map(static fn (Media $m): array => ['id' => $m->id, 'name' => $m->name, 'url' => $m->isImage() ? $m->url('thumb') : null, 'width' => $m->width, 'height' => $m->height])->all(),
                'roles' => array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], app(Access::class)->options($site->account_id)),
                'users' => $site->users()->orderBy('name')->get()->map(static fn (Model $u): array => ['id' => (int) $u->getKey(), 'name' => (string) $u->getAttribute('name')])->all(),
                'robots' => PageRequest::ROBOTS,
                'locales' => $this->localeOptions($site),
                'site_url' => $primary !== null ? 'https://'.$primary->host : rtrim((string) config('app.url'), '/'),
                'title_suffix' => $suffix,
                'mounts' => app(Mounts::class)->options($site),
                'timezone' => $site->timezone,
                'access' => $this->accessOptions($site, $form['parent_id']),
            ],
            'redirects_here' => $redirectsHere,
            'urls' => ['index' => route('basalto.pages.index'), 'save' => $entry === null ? route('basalto.pages.store') : route('basalto.pages.update', $entry), 'create' => route('basalto.pages.create')],
            'method' => $entry === null ? 'post' : 'put',
        ]);
    }

    /**
     * TB 13 Scheibe 2 (M-23): Für den Reiter «Zugriff» — ist das Modul der Mitglieder da und an, und erbt die Seite den
     * Schutz eines Vorfahren (dann ist sie geschützt, was auch immer hier steht)?
     *
     * @return array{installed: bool, enabled: bool, admin_url: string|null, modules_url: string, inherited: array{title: string, url: string}|null}
     */
    private function accessOptions(Site $site, mixed $parentId): array
    {
        $ancestor = $this->pages->protectingAncestor($site, is_numeric($parentId) ? (int) $parentId : null);
        $title = $ancestor?->latestVersion($site->default_locale)?->title;

        return app(PageAccess::class)->module($site) + ['modules_url' => route('basalto.modules'),
            'inherited' => $ancestor === null ? null : ['title' => is_string($title) && $title !== '' ? $title : '#'.$ancestor->id, 'url' => route('basalto.pages.edit', $ancestor)]];
    }

    /**
     * Passt eine Zeile zum Filter? Status in einer Sprache (oder irgendeiner), Sprache mit Fassung, Suche in Titeln und Pfad.
     *
     * @param  array<string, mixed>  $row
     * @param  array{status: string, locale: string, q: string}  $filters
     */
    private function matches(array $row, Entry $entry, array $filters): bool
    {
        $status = is_array($row['status']) ? $row['status'] : [];
        if ($filters['status'] !== '' && $entry->pageType() === PageType::Folder) {
            return false; // Ordner haben keinen Veröffentlichungsstand
        }
        if ($filters['status'] !== '') {
            $hit = $filters['locale'] !== '' ? ($status[$filters['locale']] ?? null) === $filters['status'] : in_array($filters['status'], $status, true);
            if (! $hit) {
                return false;
            }
        } elseif ($filters['locale'] !== '' && ($status[$filters['locale']] ?? null) === null) {
            return false;
        }
        if ($filters['q'] !== '') {
            $haystack = [(string) ($row['path'] ?? '')];
            foreach ($entry->versions as $version) {
                $haystack[] = $version->title;
            }

            return mb_stripos(implode("\n", $haystack), $filters['q']) !== false;
        }

        return true;
    }

    /**
     * Die Adressen einer Zeile als Vorlagen — einmal je Anfrage erzeugt statt achtmal je Zeile (N-03: 10'000 Seiten
     * wären 80'000 Aufrufe von `route()`).
     *
     * @return array<string, string>
     */
    private function rowUrlTemplates(Site $site): array
    {
        $e = self::ENTRY_PLACEHOLDER;

        return ['settings' => route('basalto.pages.edit', $e), 'content' => route('basalto.entries.edit', [$e, $site->default_locale]),
            'preview' => route('basalto.entries.preview', [$e, self::VERSION_PLACEHOLDER]), 'move' => route('basalto.pages.move', $e), 'copy' => route('basalto.pages.copy', $e),
            'review' => route('basalto.entries.review', [$e, $site->default_locale]), 'delete' => route('basalto.pages.destroy', $e), 'new_child' => route('basalto.pages.create', ['parent' => $e])];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $names
     * @param  array{urls: array<string, string>, tree: array{home: int|null, byEntry: array<int, string>}, translations: Translations, relative: \Closure(mixed): ?string, locales: list<string>}  $context  einmal je Liste vorbereitet (N-03)
     * @return array<string, mixed>
     */
    private function row(Site $site, Entry $entry, int $depth, bool $hasChildren, \Illuminate\Support\Collection $names, array $context): array
    {
        ['urls' => $urls, 'tree' => $tree, 'translations' => $translations, 'relative' => $relative] = $context;
        $url = static fn (string $name, ?int $versionId = null): string => str_replace([self::ENTRY_PLACEHOLDER, self::VERSION_PLACEHOLDER], [(string) $entry->id, (string) $versionId], $urls[$name]);
        $type = $entry->pageType();
        $isHome = $tree['home'] === $entry->id;
        // Ein Durchlauf über die Fassungen statt fünf Filter- und Sortierläufe je Zeile (N-03)
        $latestByLocale = [];
        $lastVersion = null;
        $published = false;
        foreach ($entry->versions as $version) {
            if (! isset($latestByLocale[$version->locale]) || $version->number > $latestByLocale[$version->locale]->number) {
                $latestByLocale[$version->locale] = $version;
            }
            if ($lastVersion === null || ($version->updated_at?->getTimestamp() ?? 0) > ($lastVersion->updated_at?->getTimestamp() ?? 0)) {
                $lastVersion = $version;
            }
            $published = $published || ($version->locale === $site->default_locale && $version->status === EntryStatus::Published);
        }
        $changed = $lastVersion === null ? null : ['by' => $lastVersion->author_id === null ? null : (string) ($names->get($lastVersion->author_id) ?? '—'), 'at' => $relative($lastVersion->updated_at ?? $lastVersion->created_at)];
        $status = [];
        foreach ($context['locales'] as $locale) {
            $status[$locale] = isset($latestByLocale[$locale]) ? $latestByLocale[$locale]->status->value : null;
        }
        $translation = $type !== null && $type->hasContent() ? $translations->states($entry) : [];
        $latest = $latestByLocale[$site->default_locale] ?? null;
        $target = null;
        if ($type === PageType::Redirect && is_array($entry->redirect_to)) {
            $destination = ($entry->redirect_to['kind'] ?? 'internal') === 'external' ? (string) ($entry->redirect_to['url'] ?? '') : null;
            if ($destination === null && is_numeric($entry->redirect_to['entry_id'] ?? null)) {
                $destinationEntry = Entry::query()->find((int) $entry->redirect_to['entry_id']);
                $destination = $destinationEntry === null ? null : $this->pages->urlOf($destinationEntry, $site->default_locale, false);
            }
            $target = $destination;
        }
        $hint = match (true) {
            $type === PageType::Folder => __('Ordner · nur Struktur'),
            $type !== null && $type->isError() => $type->label(),
            $type === PageType::Feed => __('Feed').' · '.(string) $entry->setting('feed.type', 'page'),
            ! $published => __('nicht veröffentlicht'),
            default => null,
        };

        return [
            'id' => $entry->id, 'title' => $latest->title ?? __('(ohne Namen)'), 'type' => $entry->type, 'type_label' => $isHome ? __('Startseite') : ($type?->label() ?? $entry->type),
            'depth' => $depth, 'parent_id' => $entry->parent_id, 'position' => $entry->position, 'has_children' => $hasChildren,
            'path' => $type !== null && $type->hasSlug() && isset($tree['byEntry'][$entry->id]) ? $this->pages->urlPath($site, $site->default_locale, $tree['byEntry'][$entry->id]) : null,
            'status' => $status, 'translation' => $translation, 'hint' => $hint, 'target' => $target, 'is_home' => $isHome, 'changed' => $changed,
            'urls' => [
                'settings' => $url('settings'),
                'content' => $type === PageType::Page ? $url('content') : null,
                'preview' => $latest === null || $type === null || ! $type->hasContent() ? null : $url('preview', $latest->id),
                'move' => $url('move'),
                'copy' => $url('copy'),
                'review' => $type !== null && $type->hasContent() ? $url('review') : null,
                'delete' => $url('delete'),
                'new_child' => in_array($type, [PageType::Page, PageType::Folder], true) ? $url('new_child') : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function settingsFrom(PageRequest $request, PageType $type): array
    {
        $settings = [];
        foreach (['routing', 'meta', 'image', 'canonical', 'access', 'layout', 'cache', 'rights', 'expert', 'keyboard', 'publication', 'feed'] as $tab) {
            if (! $type->hasTab($tab) && ! ($tab === 'feed' && $type === PageType::Feed)) {
                continue;
            }
            $value = $request->input($tab);
            if (! is_array($value)) {
                continue;
            }
            if ($tab === 'routing') {
                unset($value['slugs'], $value['home']);
                $value['priority'] = (int) ($value['priority'] ?? 0);
                $value['redirect_on_slug_change'] = (bool) ($value['redirect_on_slug_change'] ?? true);
            }
            if ($tab === 'meta') {
                $value = ['robots' => $value['robots'] ?? null];
            }
            // TB 13 Scheibe 2 (M-23): «Nur für Mitglieder» und ob der Schutz für Unterseiten gilt (ab Werk ja)
            if ($tab === 'access') {
                $value = ['protected' => (bool) ($value['protected'] ?? false), 'children' => (bool) ($value['children'] ?? true)];
            }
            if ($tab === 'cache') {
                $value = ['page_cache' => (bool) ($value['page_cache'] ?? true), 'shared_max_age' => (int) ($value['shared_max_age'] ?? 0), 'private_max_age' => (int) ($value['private_max_age'] ?? 0), 'always_shared' => (bool) ($value['always_shared'] ?? false)];
            }
            $settings[$tab] = $value;
        }

        return $settings;
    }

    /** @return array<string, mixed>|null */
    private function redirectFrom(PageRequest $request, PageType $type): ?array
    {
        if ($type !== PageType::Redirect) {
            return null;
        }
        $redirect = $request->input('redirect');
        $redirect = is_array($redirect) ? $redirect : [];

        // Externe Ziele je Sprache: leer heisst «wie die Hauptsprache»
        $urls = array_filter(is_array($redirect['urls'] ?? null) ? $redirect['urls'] : [], static fn (mixed $url, mixed $locale): bool => is_string($locale) && is_string($url) && trim($url) !== '', ARRAY_FILTER_USE_BOTH);

        return ['kind' => $redirect['kind'] ?? 'internal', 'entry_id' => is_numeric($redirect['entry_id'] ?? null) ? (int) $redirect['entry_id'] : null,
            'url' => (string) ($redirect['url'] ?? ''), 'urls' => array_map('trim', $urls), 'status' => (int) ($redirect['status'] ?? 302) === 301 ? 301 : 302];
    }

    /** Name, Slug und Metadaten je Sprache: nur bei Änderung — in den offenen Entwurf, sonst eine neue Entwurfsfassung. */
    private function saveVersions(Site $site, Entry $entry, PageRequest $request): void
    {
        foreach ($site->localeCodes() as $locale) {
            $name = $request->input('name.'.$locale);
            $name = is_string($name) ? trim($name) : '';
            $latest = $entry->latestVersion($locale);
            // Autosave: was das Formular unverändert mitschickt, überschreibt keine neuere Änderung aus dem Editor
            $base = $request->input('base.'.$locale);
            if (is_array($base) && $latest !== null) {
                $name = $name === trim((string) ($base['name'] ?? '')) ? $latest->title : $name;
            }
            if ($name === '' && $latest === null) {
                continue;
            }
            $slug = $request->input('routing.slugs.'.$locale);
            $slug = is_string($slug) && $slug !== '' ? $slug : ($latest->slug ?? str($name)->slug()->toString());
            $meta = ['title' => (string) $request->input('meta.'.$locale.'.title', ''), 'description' => (string) $request->input('meta.'.$locale.'.description', '')];
            $currentMeta = is_array($latest?->data['settings']['meta'] ?? null) ? $latest->data['settings']['meta'] : [];
            $unchanged = $latest !== null && $latest->title === $name && $latest->slug === $slug
                && (string) ($currentMeta['title'] ?? '') === $meta['title'] && (string) ($currentMeta['description'] ?? '') === $meta['description'];
            if ($unchanged) {
                continue;
            }
            $data = is_array($latest?->data) ? $latest->data : ['content' => []];
            $data['title'] = $name !== '' ? $name : ($latest->title ?? '');
            $data['slug'] = $slug;
            $data['settings'] = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $data['settings']['meta'] = $meta;
            $author = $request->user()?->getAuthIdentifier();
            // Wie im Editor: in den offenen Entwurf schreiben, keine neue Fassung je Autosave
            $version = $entry->saveDraft($locale, $data, is_numeric($author) ? (int) $author : null, $latest?->source_version_id);
            event(new EntrySaved($entry, $version));
        }
    }

    /** Startseite je Sprache als Site-Einstellung `home.<locale>` (Reiter Routing). */
    private function saveHome(Site $site, Entry $entry, PageRequest $request): void
    {
        if (! $request->type()->hasTab('routing')) {
            return;
        }
        $settings = $site->settings ?? [];
        $changed = false;
        foreach ($site->localeCodes() as $locale) {
            $wants = (bool) $request->input('routing.home.'.$locale, false);
            $current = $settings['home'][$locale] ?? null;
            if ($wants && (int) $current !== $entry->id) {
                $settings['home'][$locale] = $entry->id;
                $changed = true;
            } elseif (! $wants && (int) $current === $entry->id) {
                unset($settings['home'][$locale]);
                $changed = true;
            }
        }
        if ($changed) {
            $site->settings = $settings;
            $site->save();
        }
    }

    private function done(PageRequest $request, Entry $entry, string $status): RedirectResponse
    {
        if ($request->boolean('new')) {
            return redirect()->route('basalto.pages.create', ['type' => $entry->type, 'parent' => $entry->parent_id])->with('status', $status);
        }
        if ($request->boolean('close')) {
            return redirect()->route('basalto.pages.index')->with('status', $status);
        }

        return redirect()->route('basalto.pages.edit', $entry)->with('status', $status);
    }

    /** @return list<array{value: string, label: string, description: string}> */
    private function typeOptions(): array
    {
        return [
            ['value' => 'page', 'label' => __('Seite'), 'description' => __('Inhalt mit Blöcken, eigene Adresse')],
            ['value' => 'redirect', 'label' => __('Weiterleitung'), 'description' => __('auf eine Seite dieser Site oder eine externe Adresse')],
            ['value' => 'error_404', 'label' => __('Fehlerseite'), 'description' => __('401 · 403 · 404 · 503 — eine je Code und Sprache')],
            ['value' => 'feed', 'label' => __('Feed'), 'description' => __('RSS mit den Inhalten eines Typs')],
            ['value' => 'folder', 'label' => __('Ordner'), 'description' => __('nur Struktur, keine Adresse')],
        ];
    }

    /** @return list<array{locale: string, is_default: bool, is_required: bool}> */
    private function localeOptions(Site $site): array
    {
        $options = [];
        foreach ($site->locales as $locale) {
            $options[] = ['locale' => $locale->locale, 'is_default' => (bool) $locale->is_default, 'is_required' => (bool) $locale->is_required];
        }

        return $options === [] ? [['locale' => $site->default_locale, 'is_default' => true, 'is_required' => true]] : $options;
    }

    /** @param  Collection<int, Entry>  $entries */
    private function depthOf(Entry $entry, Collection $entries): int
    {
        $depth = 0;
        for ($node = $entry; $node->parent_id !== null && $depth < 20; $depth++) {
            $node = $entries->find($node->parent_id);
            if ($node === null) {
                break;
            }
        }

        return $depth;
    }
}
