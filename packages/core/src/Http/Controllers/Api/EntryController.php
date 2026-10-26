<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers\Api;

use Basalto\Core\Access\Access;
use Basalto\Core\Content\Completeness;
use Basalto\Core\Content\DraftInput;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\PageType;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Content\WorkflowException;
use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Http\Resources\EntryResource;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeLoader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inhalte per REST-API (M-17): Liste mit Paginierung und Filtern, einzeln mit Sprache und Fallback-Kette, anlegen,
 * ändern (offener Entwurf oder neue Entwurfsfassung — wie der Editor), Workflow-Schritte und Papierkorb. Die Site kommt
 * aus dem Token; Inhalte anderer Sites gibt es hier nicht (404). Rechte über die EntryPolicy wie im Backend; Regeln des
 * Statusmodells (Vier-Augen, Pflichtsprachen, Slug, Alt-Text) aus dem Modell — Verstösse als 422 unter `workflow`.
 */
final class EntryController
{
    private const PER_PAGE = 25;

    /** @var array<string, string> Workflow-Schritt → Fähigkeit der Policy */
    private const ACTIONS = ['submit' => 'update', 'withdraw' => 'update', 'approve' => 'approve', 'reject' => 'approve', 'schedule' => 'publish', 'unschedule' => 'publish', 'publish' => 'publish', 'unpublish' => 'publish'];

    public function __construct(private readonly CurrentSite $current, private readonly ThemeLoader $themes, private readonly DraftInput $drafts, private readonly Pages $pages) {}

    /** Liste: `type`, `status` (mit `locale`: Status in dieser Sprache), `locale`, `q` (Titel), `updated_since`, `per_page`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $site = $this->current->require();
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(EntryStatus::class)],
            'locale' => ['nullable', Rule::in($site->localeCodes())],
            'q' => ['nullable', 'string', 'max:200'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $locale = $filters['locale'] ?? null;
        $query = Entry::query()->with('versions')->orderByDesc('id');
        if (($filters['type'] ?? null) !== null) {
            $query->where('type', $filters['type']);
        }
        if (($filters['status'] ?? null) !== null || $locale !== null) {
            $query->whereHas('versions', static function (Builder $versions) use ($filters, $locale): void {
                $versions->when($filters['status'] ?? null, static fn (Builder $q, string $status) => $q->where('status', $status))
                    ->when($locale, static fn (Builder $q, string $code) => $q->where('locale', $code));
            });
        }
        // Gross/klein egal — auch auf PostgreSQL, wo «like» die Schreibweise unterscheidet
        if (($filters['q'] ?? '') !== '') {
            $query->whereHas('versions', static fn (Builder $versions) => $versions->whereLike('title', '%'.$filters['q'].'%'));
        }
        if (($filters['updated_since'] ?? null) !== null) {
            $since = CarbonImmutable::parse($filters['updated_since'])->utc();
            $query->where(static fn (Builder $q) => $q->where('updated_at', '>=', $since)->orWhereHas('versions', static fn (Builder $v) => $v->where('updated_at', '>=', $since)));
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? self::PER_PAGE))->withQueryString();
        $page->getCollection()->each(static fn (Entry $entry) => $entry->setRelation('site', $site));

        return EntryResource::collection($page);
    }

    /** Einzeln — mit `?locale=` die Fassung, die ein Leser in der Sprache sieht (Fallback-Kette), und der neueste Stand. */
    public function show(Request $request, int $id): EntryResource
    {
        $site = $this->current->require();
        $entry = $this->find($id, $site);
        Gate::authorize('view', $entry);
        $locale = $request->validate(['locale' => ['nullable', Rule::in($site->localeCodes())]])['locale'] ?? null;

        return (new EntryResource($entry))->withLocale($locale);
    }

    /** Anlegen: ein Inhalt eines Typs aus dem Theme (Seiten entstehen im Seitenbaum), erste Fassung als Entwurf in der Standardsprache. */
    public function store(Request $request): JsonResponse
    {
        $site = $this->current->require();
        Gate::authorize('create', new Entry(['site_id' => $site->id]));
        $schema = ThemeSchema::fromTheme($this->themes->forSite($site));
        $allowed = array_values(array_filter(array_keys($schema->types), static fn (string $n): bool => ! PageType::isPageType($n)));
        $type = (string) $request->validate(['type' => ['required', 'string', Rule::in($allowed)]], ['type.in' => __('Unbekannter Inhaltstyp — erlaubt sind: :types. Seiten entstehen im Seitenbaum.', ['types' => implode(', ', $allowed)])])['type'];
        $entry = new Entry(['site_id' => $site->id, 'type' => $type, 'created_by' => $this->userId($request)]);
        $entry->setRelation('site', $site);
        $data = $this->drafts->data($entry, $request->all(), null);
        $version = DB::transaction(function () use ($entry, $site, $data, $request): EntryVersion {
            $entry->save();

            return $entry->newDraft($site->default_locale, $data, $this->userId($request));
        });
        event(new EntrySaved($entry, $version));

        return (new EntryResource($entry->load('versions')))->withLocale($site->default_locale)->response()->setStatusCode(201);
    }

    /**
     * Ändern in einer Sprache (`locale`, sonst Standardsprache): Was fehlt, kommt aus der neuesten Fassung. Ist sie ein
     * Entwurf, wird er weitergeschrieben — sonst entsteht eine neue Entwurfsfassung (wie der Autosave im Editor).
     */
    public function update(Request $request, int $id): EntryResource
    {
        $site = $this->current->require();
        $entry = $this->find($id, $site);
        Gate::authorize('update', $entry);
        $locale = $this->locale($request, $site);
        app(Access::class)->authorizeLocale($request->user(), $site, $locale);
        $previous = $entry->latestVersion($locale);
        $input = $request->only(['title', 'slug', 'intro', 'content', 'fields']);
        if ($previous !== null) {
            $input += ['title' => $previous->title, 'intro' => $previous->data['intro'] ?? null, 'content' => $previous->data['content'] ?? []];
        }
        $data = $this->drafts->data($entry, $input, $previous);
        $version = $entry->saveDraft($locale, $data, $this->userId($request), $previous?->source_version_id);
        event(new EntrySaved($entry, $version));

        return (new EntryResource($entry->load('versions')))->withLocale($locale);
    }

    /** In den Papierkorb (wie im Seitenbaum) — wiederherstellen geht im Backend. */
    public function destroy(int $id): Response
    {
        $site = $this->current->require();
        $entry = $this->find($id, $site);
        Gate::authorize('delete', $entry);
        $this->pages->trackPaths($site, static function () use ($entry): void {
            $entry->delete();
        });
        $this->pages->forget();

        return response()->noContent();
    }

    /** Ein Workflow-Schritt in einer Sprache — Rolle je Schritt über die Policy, Regeln im Modell. */
    public function workflow(Request $request, int $id, string $action): EntryResource
    {
        $site = $this->current->require();
        $entry = $this->find($id, $site);
        abort_unless(isset(self::ACTIONS[$action]), 404);
        Gate::authorize(self::ACTIONS[$action], $entry);
        $locale = $this->locale($request, $site);
        $version = $action === 'unpublish' ? $entry->publishedVersion($locale) : $entry->latestVersion($locale);
        abort_if($version === null, 404, __('In dieser Sprache gibt es keine passende Fassung.'));
        $userId = (int) $this->userId($request);
        try {
            match ($action) {
                'submit' => $this->submit($entry, $version, $userId),
                'withdraw' => $version->withdraw(),
                'approve' => $version->approve($userId),
                'reject' => $version->reject($userId, (string) $request->validate(['comment' => ['required', 'string', 'max:2000']])['comment']),
                'schedule' => $version->schedule($this->scheduledAt($request)),
                'unschedule' => $version->unschedule(),
                'publish' => $version->publish(),
                'unpublish' => $version->unpublish(),
            };
        } catch (WorkflowException $e) {
            throw ValidationException::withMessages(['workflow' => $e->getMessage()]);
        }

        return (new EntryResource($entry->load('versions')))->withLocale($locale);
    }

    private function submit(Entry $entry, EntryVersion $version, int $userId): void
    {
        // Einreichen verlangt alle Pflichtfelder — wie im Editor
        $missing = app(Completeness::class)->missing($entry, $version);
        if ($missing !== []) {
            throw ValidationException::withMessages($missing + ['workflow' => trans_choice('Einreichen erst mit allen Pflichtfeldern — :count fehlt.|Einreichen erst mit allen Pflichtfeldern — :count fehlen.', count($missing), ['count' => count($missing)])]);
        }
        $version->submit($userId);
    }

    private function scheduledAt(Request $request): CarbonImmutable
    {
        $at = CarbonImmutable::parse((string) $request->validate(['at' => ['required', 'date', 'after:now']], [], ['at' => __('Zeitpunkt')])['at']);

        return $at->utc();
    }

    /** Ein Inhalt dieser Site — der Site-Scope filtert, ein fremder ist «nicht gefunden». */
    private function find(int $id, Site $site): Entry
    {
        $entry = Entry::query()->with('versions')->findOrFail($id);
        $entry->setRelation('site', $site);

        return $entry;
    }

    private function locale(Request $request, Site $site): string
    {
        return (string) ($request->validate(['locale' => ['nullable', Rule::in($site->localeCodes())]])['locale'] ?? $site->default_locale);
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
