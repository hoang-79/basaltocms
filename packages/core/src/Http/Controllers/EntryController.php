<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Access\Access;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Content\Completeness;
use Basalto\Core\Content\DraftInput;
use Basalto\Core\Content\EntryRenderer;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Content\Translations;
use Basalto\Core\Content\VersionDiff;
use Basalto\Core\Content\WorkflowException;
use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Preferences;
use Basalto\Core\Theme\ThemeException;
use Basalto\Core\Theme\ThemeLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Inhalte der aktuellen Site: Liste, Anlegen, Bearbeiten je Sprache (Blöcke aus dem Theme), Einreichen, Übersetzen, Vorschau. */
final class EntryController
{
    public function __construct(private readonly CurrentSite $current, private readonly ThemeLoader $themes, private readonly Dates $dates, private readonly Pages $pages, private readonly EntryRenderer $renderer) {}

    /** Typ-Listen (Bereich 10): je Inhaltstyp ein Reiter mit Zahl; Seiten liegen im Baum, nicht hier. */
    public function index(Request $request): InertiaResponse
    {
        $site = $this->current->require();
        $locales = $site->localeCodes();
        $schemaTypes = [];
        $theme = null;
        try {
            $theme = $this->themes->forSite($site);
            $schemaTypes = ThemeSchema::fromTheme($theme)->types;
        } catch (ThemeException) {
        }
        $counts = Entry::query()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $types = [];
        foreach ($schemaTypes as $name => $type) {
            if (! PageType::isPageType($name)) {
                $types[] = ['name' => $name, 'label' => $type['label'], 'count' => (int) ($counts->get($name) ?? 0), 'in_theme' => true];
            }
        }
        foreach ($counts as $name => $n) {
            if (! isset($schemaTypes[$name]) && ! PageType::isPageType((string) $name)) {
                // TB 29: Einzelinhalte (global) mit ihrer Beschriftung aus dem Theme statt der Kennung
                $global = $theme?->global((string) $name);
                $types[] = ['name' => (string) $name, 'label' => $global === null ? (string) $name : ($global->value('label', app()->getLocale()) ?? ucfirst((string) $name)), 'count' => (int) $n, 'in_theme' => $global !== null, 'global' => $global !== null];
            }
        }
        $active = $request->query('type');
        $active = is_string($active) && in_array($active, array_column($types, 'name'), true) ? $active : ($types[0]['name'] ?? null);
        $filters = ['status' => (string) $request->query('status', ''), 'locale' => (string) $request->query('locale', ''), 'q' => (string) $request->query('q', '')];
        // TB 21: Sortierung und Seitengrösse nach dem Profil; «nach Titel» nimmt den Titel der neuesten Fassung
        $query = Preferences::sort(Entry::query()->with('versions')->where('type', $active ?? ''), $request->user(),
            static fn ($q) => $q->orderBy(EntryVersion::query()->select('title')->whereColumn('entry_id', 'entries.id')->latest('id')->limit(1))->orderBy('id'));
        if ($filters['status'] !== '') {
            $query->whereHas('versions', static fn ($q) => $q->where('status', $filters['status'])->when($filters['locale'] !== '', static fn ($q) => $q->where('locale', $filters['locale'])));
        } elseif ($filters['locale'] !== '') {
            $query->whereHas('versions', static fn ($q) => $q->where('locale', $filters['locale']));
        }
        if ($filters['q'] !== '') {
            $query->whereHas('versions', static fn ($q) => $q->where('title', 'like', '%'.$filters['q'].'%'));
        }
        $page = $query->simplePaginate(Preferences::perPage($request->user()))->withQueryString();
        $rows = $page->getCollection()->map(fn (Entry $entry): array => $this->row($entry, $site, $locales))->values()->all();

        return Inertia::render('core::Entries', [
            'types' => $types, 'active' => $active, 'entries' => $rows, 'filters' => $filters,
            // TB 29: Fussleiste nach dem Profil — vorher stand fest «neueste zuerst · 50 je Seite»
            'pagination' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'sort' => Preferences::labels()['list_sorts'][Preferences::of($request->user())['list_sort']]],
            'locales' => $locales, 'site' => $site->only(['handle', 'name']),
            'create_url' => route('basalto.entries.store'), 'missing_type' => $active !== null && ! isset($schemaTypes[$active]),
            'can_create' => Gate::allows('create', new Entry(['site_id' => $site->id])), 'types_url' => route('basalto.setup.types.index'),
        ]);
    }

    /** Neuer Inhalt eines Typs aus dem Theme oder der Site — Seiten entstehen im Seitenbaum. */
    public function store(Request $request): RedirectResponse
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));
        $schema = ThemeSchema::fromTheme($this->themes->forSite($site));
        $allowed = array_values(array_filter(array_keys($schema->types), static fn (string $n): bool => ! PageType::isPageType($n)));
        $validated = $request->validate(['type' => ['required', 'string', Rule::in($allowed)]]);
        $type = (string) $validated['type'];
        $entry = Entry::query()->create(['type' => $type, 'created_by' => $request->user()?->getAuthIdentifier()]);
        $version = $entry->newDraft($site->default_locale, ['title' => __('Neu: :type', ['type' => $schema->types[$type]['label']]), 'content' => []], $request->user()?->getAuthIdentifier());
        event(new EntrySaved($entry, $version));

        return redirect()->route('basalto.entries.edit', [$entry, $site->default_locale]);
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function row(Entry $entry, Site $site, array $locales): array
    {
        $status = [];
        foreach ($locales as $locale) {
            $status[$locale] = $entry->versions->where('locale', $locale)->sortByDesc('number')->first()?->status->value;
        }
        $latest = $entry->versions->where('locale', $site->default_locale)->sortByDesc('number')->first();

        return ['id' => $entry->id, 'type' => $entry->type, 'title' => $latest->title ?? __('(ohne Titel)'), 'status' => $status, 'translation' => app(Translations::class)->states($entry),
            'date' => $this->dates->date($entry->created_at), 'edit_url' => route('basalto.entries.edit', [$entry, $site->default_locale]),
            'preview_url' => $latest === null ? null : route('basalto.entries.preview', [$entry, $latest])];
    }

    public function edit(Entry $entry, string $locale): InertiaResponse
    {
        Gate::authorize('view', $entry);
        $site = $entry->site;
        abort_unless(in_array($locale, $site->localeCodes(), true), 404);
        $theme = $this->themes->forSite($site);
        $schema = ThemeSchema::fromTheme($theme);
        $version = $entry->latestVersion($locale);
        $versions = $entry->versions()->where('locale', $locale)->orderByDesc('number')->get();
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $authors = $userModel::query()->whereIn('id', $versions->pluck('author_id')->filter()->unique())->pluck('name', 'id');
        $history = [];
        foreach ($versions as $v) {
            $previous = $versions->firstWhere('number', $v->number - 1);
            $history[] = ['id' => $v->id, 'number' => $v->number, 'status' => $v->status->value, 'status_label' => $v->status->label(),
                'author' => $v->author_id === null ? null : (string) ($authors->get($v->author_id) ?? '—'),
                'created_at' => $this->dates->dateTime($v->created_at), 'preview_url' => route('basalto.entries.preview', [$entry, $v]),
                'restore_url' => route('basalto.entries.restore', [$entry, $locale, $v]), 'changes' => VersionDiff::between($previous, $v)];
        }
        $localeTabs = [];
        foreach ($site->locales as $siteLocale) {
            $latest = $entry->latestVersion($siteLocale->locale);
            $localeTabs[] = ['locale' => $siteLocale->locale, 'is_default' => $siteLocale->is_default, 'status' => $latest?->status->value,
                'translation' => app(Translations::class)->state($entry, $siteLocale->locale),
                'url' => route('basalto.entries.edit', [$entry, $siteLocale->locale])];
        }

        return Inertia::render('core::EntryEdit', [
            'entry' => ['id' => $entry->id, 'type' => $entry->type, 'uuid' => $entry->uuid],
            'site' => ['handle' => $site->handle, 'name' => $site->name, 'default_locale' => $site->default_locale],
            'locale' => $locale,
            'locales' => $localeTabs,
            'version' => $version === null ? null : ['id' => $version->id, 'number' => $version->number, 'status' => $version->status->value,
                'status_label' => $version->status->label(), 'title' => $version->title, 'slug' => $version->slug, 'data' => $version->data,
                'can_edit' => $version->status->value === 'draft', 'preview_url' => route('basalto.entries.preview', [$entry, $version])],
            'schema' => $schema->toArray(),
            'fields' => array_values(array_filter($schema->types[$entry->type]['fields'] ?? [], static fn (array $f): bool => $f['type'] !== 'blocks' && ! in_array($f['name'], ['title', 'slug', 'intro'], true) && ! ($f['type'] === 'seo' && $entry->pageType() !== null))),
            'type_label' => $schema->types[$entry->type]['label'] ?? $entry->type,
            'has_content' => ($schema->types[$entry->type]['allowed'] ?? []) !== [] || PageType::isPageType($entry->type),
            'settings_url' => $entry->pageType() !== null ? route('basalto.pages.edit', $entry) : null,
            'allowed' => $schema->allowedBlocks($entry->type),
            'blocked' => $this->blocked($schema, $entry),
            'settings_excerpt' => $this->settingsExcerpt($entry, $version, $schema),
            'media' => Media::query()->latest('id')->limit(100)->get()->map(static fn (Media $m): array => ['id' => $m->id, 'name' => $m->name, 'url' => $m->isImage() ? $m->url('thumb') : null, 'mime' => $m->mime, 'width' => $m->width, 'height' => $m->height, 'full' => $m->url($m->variants ? 'content' : null)])->all(),
            'history' => $history,
            'review' => ['url' => route('basalto.entries.review', [$entry, $locale]), 'stage' => $version?->status->value ?? 'draft',
                'rejection' => $version !== null && $version->status === EntryStatus::Draft && $version->comment !== null
                    ? ['comment' => $version->comment, 'by' => $version->reviewed_by === null ? null : (string) ($userModel::query()->whereKey($version->reviewed_by)->value('name') ?? '—'), 'at' => $this->dates->dateTime($version->updated_at)]
                    : null],
            'urls' => ['index' => route('basalto.entries.index'), 'save' => route('basalto.entries.update', [$entry, $locale]),
                'submit' => route('basalto.entries.submit', [$entry, $locale]), 'translate' => route('basalto.entries.translate', [$entry, $locale]),
                'preview_live' => route('basalto.entries.preview.live', [$entry, $locale]), 'share' => route('basalto.entries.share', [$entry, $locale]),
                'paste' => route('basalto.media.paste'), 'media_index' => route('basalto.media.index'),
                'translation' => $locale === $site->default_locale ? null : route('basalto.entries.translation', [$entry, $locale])],
            'status' => session('status'),
            // TB 49: wer den Inhalt gerade ebenfalls bearbeitet
            'presence' => PresenceController::config($entry, (int) auth()->id()),
        ]);
    }

    /** Speichern = neue Entwurfs-Fassung (Nummer + 1) mit Titel, Slug und Blockliste, geprüft gegen das Theme-Schema. */
    public function update(Request $request, Entry $entry, string $locale): RedirectResponse
    {
        Gate::authorize('update', $entry);
        app(Access::class)->authorizeLocale($request->user(), $entry->site, $locale);
        $site = $entry->site;
        abort_unless(in_array($locale, $site->localeCodes(), true), 404);
        $previous = $entry->latestVersion($locale);
        // Autosave: ein Entwurf darf unvollständig sein — Pflichtfelder prüft das Einreichen
        $data = app(DraftInput::class)->data($entry, $request->all(), $previous);
        // Eine Übersetzung bleibt auf ihrer Quelle stehen — aktuell wird sie erst über «Übersetzen nebeneinander» (M-06)
        // Autosave: in den offenen Entwurf schreiben, eine neue Fassung nur nach Einreichen/Freigabe/Veröffentlichung
        $version = $entry->saveDraft($locale, $data, $request->user()?->getAuthIdentifier(), $previous?->source_version_id);
        event(new EntrySaved($entry, $version));

        return redirect()->route('basalto.entries.edit', [$entry, $locale])->with('status', __('Fassung :number gespeichert.', ['number' => $version->number]));
    }

    public function submit(Request $request, Entry $entry, string $locale): RedirectResponse
    {
        Gate::authorize('update', $entry);
        app(Access::class)->authorizeLocale($request->user(), $entry->site, $locale);
        $version = $entry->latestVersion($locale);
        abort_if($version === null, 404);
        // Einreichen verlangt alle Pflichtfelder (beim Autosave durfte der Entwurf unvollständig sein)
        $missing = app(Completeness::class)->missing($entry, $version);
        if ($missing !== []) {
            return back()->withErrors($missing + ['workflow' => trans_choice('Einreichen erst mit allen Pflichtfeldern — :count fehlt, am Feld markiert.|Einreichen erst mit allen Pflichtfeldern — :count fehlen, am Feld markiert.', count($missing), ['count' => count($missing)])]);
        }
        try {
            $version->submit(is_numeric($request->user()?->getAuthIdentifier()) ? (int) $request->user()->getAuthIdentifier() : null);
        } catch (WorkflowException $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }

        return back()->with('status', __('Fassung :number eingereicht — sie steht jetzt in «Freigabe».', ['number' => $version->number]));
    }

    /** Übersetzung anlegen: Struktur der neuesten Fassung der Standardsprache als Entwurf in der Zielsprache, Texte leer. */
    public function translate(Request $request, Entry $entry, string $locale): RedirectResponse
    {
        Gate::authorize('update', $entry);
        $site = $entry->site;
        abort_unless(in_array($locale, $site->localeCodes(), true), 404);
        // Dieselbe Quelle wie Stand und Übersetzen nebeneinander: die neueste Fassung der Standardsprache
        $source = $entry->latestVersion($site->default_locale);
        abort_if($source === null, 404, __('Keine Fassung in der Standardsprache'));
        abort_if($entry->latestVersion($locale) !== null, 409, __('In dieser Sprache gibt es schon eine Fassung'));
        // Struktur der Quelle, übersetzbare Felder leer — die Übersetzung beginnt nebeneinander (M-06, Mockup 12)
        $version = $entry->newDraft($locale, app(Translations::class)->scaffold($entry, $source, $locale), $request->user()?->getAuthIdentifier(), $source->id);
        event(new EntrySaved($entry, $version));

        return redirect()->route('basalto.entries.translation', [$entry, $locale])->with('status', __('Übersetzung :locale aus :source angelegt (Fassung :number) — jetzt Feld für Feld übersetzen.', ['locale' => strtoupper($locale), 'source' => strtoupper($site->default_locale), 'number' => $version->number]));
    }

    public function preview(Entry $entry, EntryVersion $version, EntryRenderer $renderer): Response
    {
        Gate::authorize('view', $entry);
        abort_unless($version->entry_id === $entry->id, 404);

        return response($renderer->html($version, $this->previewVars($entry, $version->locale)))->header('X-Robots-Tag', 'noindex');
    }

    /** Live-Vorschau (M-05): rendert den ungespeicherten Stand aus dem Editor mit dem echten Theme — nichts wird gespeichert. */
    public function previewLive(Request $request, Entry $entry, string $locale, EntryRenderer $renderer): Response
    {
        Gate::authorize('view', $entry);
        abort_unless(in_array($locale, $entry->site->localeCodes(), true), 404);
        $latest = $entry->latestVersion($locale);
        $data = is_array($latest?->data) ? $latest->data : [];
        $fields = $request->input('fields', []);
        foreach (is_array($fields) ? $fields : [] as $name => $value) {
            $data[(string) $name] = $value;
        }
        $content = $request->input('content', []);
        $data = ['title' => (string) $request->input('title', $latest->title ?? ''), 'intro' => $request->input('intro'), 'content' => is_array($content) ? array_values($content) : []] + $data;
        $version = new EntryVersion(['locale' => $locale, 'number' => 0, 'status' => EntryStatus::Draft->value, 'title' => $data['title'], 'slug' => $latest->slug ?? str($data['title'])->slug()->toString(), 'data' => $data]);
        $version->setRelation('entry', $entry);

        // TB 30: mit Feldmarken — das Vorschau-Fenster zeigt, wo das Feld mit dem Fokus steht
        return response($renderer->html($version, $this->previewVars($entry, $locale), true))->header('X-Robots-Tag', 'noindex');
    }

    /** Vorschau-Link für Dritte (M-05): signiert, 72 Stunden gültig, ohne Anmeldung. */
    public function share(Entry $entry, string $locale): JsonResponse
    {
        Gate::authorize('view', $entry);
        $expires = now()->addHours(72);

        return response()->json(['url' => URL::temporarySignedRoute('basalto.entries.shared', $expires, ['entry' => $entry->id, 'locale' => $locale]), 'expires_at' => $this->dates->dateTime($expires)]);
    }

    /** Die geteilte Vorschau: neueste Fassung der Sprache, nur mit gültiger Signatur, nie im Index. */
    public function shared(Entry $entry, string $locale, EntryRenderer $renderer): Response
    {
        $version = $entry->latestVersion($locale);
        abort_if($version === null, 404);
        $this->current->set($entry->site);

        return response($renderer->html($version, $this->previewVars($entry, $locale)))->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Eine ältere Fassung wiederherstellen = neue Entwurfsfassung mit genau ihren Blöcken und Feldern (M-04). */
    public function restore(Request $request, Entry $entry, string $locale, EntryVersion $version): RedirectResponse
    {
        Gate::authorize('update', $entry);
        app(Access::class)->authorizeLocale($request->user(), $entry->site, $locale);
        abort_unless($version->entry_id === $entry->id && $version->locale === $locale, 404);
        $author = $request->user()?->getAuthIdentifier();
        $draft = $entry->newDraft($locale, ['title' => $version->title, 'slug' => $version->slug] + $version->data, is_numeric($author) ? (int) $author : null, $version->source_version_id);
        event(new EntrySaved($entry, $draft));

        return redirect()->route('basalto.entries.edit', [$entry, $locale])->with('status', __('Fassung :number wiederhergestellt als Fassung :new.', ['number' => $version->number, 'new' => $draft->number]));
    }

    /**
     * Seitenvariablen der Vorschau: Pfad und URL aus den neuesten Fassungen, damit Alternativen und kanonische URL stimmen.
     *
     * @return array<string, mixed>
     */
    private function previewVars(Entry $entry, string $locale): array
    {
        $site = $entry->site;
        $version = $entry->latestVersion($locale);
        if ($version === null) {
            return [];
        }
        $path = $this->pages->pathOf($entry, $locale, false) ?? '/';

        return $this->pages->pageVariables($site, $entry, $version, $locale, $path, request()->getSchemeAndHttpHost());
    }

    /**
     * Blöcke des Themes, die der Typ nicht erlaubt — sichtbar in der Palette, mit Grund (L-09).
     *
     * @return list<array{name: string, label: string, reason: string}>
     */
    private function blocked(ThemeSchema $schema, Entry $entry): array
    {
        $allowed = $schema->allowedBlocks($entry->type);
        $label = $schema->types[$entry->type]['label'] ?? $entry->type;
        $blocked = [];
        foreach ($schema->blocks as $name => $block) {
            if (! in_array($name, $allowed, true)) {
                $blocked[] = ['name' => $name, 'label' => $block['label'], 'reason' => __('«:block» ist für den Typ :type nicht erlaubt (type :handle · allow, Station E)', ['block' => $block['label'], 'type' => $label, 'handle' => $entry->type])];
            }
        }

        return $blocked;
    }

    /**
     * Der Auszug der Seiteneinstellungen im Editor (E-25): eine Quelle, hier nur Anzeige mit dem Weg zu den zwölf Reitern.
     *
     * @return array<string, string>|null
     */
    private function settingsExcerpt(Entry $entry, ?EntryVersion $version, ThemeSchema $schema): ?array
    {
        $type = $entry->pageType();
        if ($type === null) {
            return null;
        }
        $meta = is_array($version?->data['settings']['meta'] ?? null) ? $version->data['settings']['meta'] : [];
        $expert = is_array($entry->setting('expert')) ? $entry->setting('expert') : [];
        $visible = [];
        if (! ($expert['hide_in_menu'] ?? false)) {
            $visible[] = __('Menü');
        }
        if ($expert['in_sitemap'] ?? true) {
            $visible[] = __('Sitemap');
        }
        if ($expert['in_search'] ?? true) {
            $visible[] = __('Suche');
        }
        $from = (string) $entry->setting('publication.visible_from', '');
        $until = (string) $entry->setting('publication.visible_until', '');

        return [
            'type_label' => $this->pages->isHome($entry, $entry->site->default_locale) ? __('Startseite') : $type->label(),
            'template' => $this->renderer->templateFor($entry, $schema),
            'slug' => $version === null ? '' : $this->pages->urlOf($entry, $version->locale, false) ?? '/'.$version->slug,
            'meta_title' => (string) ($meta['title'] ?? ''),
            'robots' => (string) ($entry->setting('meta.robots') ?? 'index, follow'),
            'visible' => $visible === [] ? '—' : implode(' · ', $visible),
            'window' => $from === '' && $until === '' ? '—' : trim(($from !== '' ? __('ab :date', ['date' => $from]) : '').' '.($until !== '' ? __('bis :date', ['date' => $until]) : '')),
        ];
    }
}
