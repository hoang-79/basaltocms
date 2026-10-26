<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Backend\Dates;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Content\EntryStatus;
use Basalto\Core\Content\Pages;
use Basalto\Core\Content\ThemeSchema;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\EntryVersion;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Modules\Comments\CommentStatus;
use Basalto\Modules\Comments\Frontend\CommentsRenderer;
use Basalto\Modules\Comments\Models\Comment;
use Basalto\Modules\Comments\SpamCounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Die zentrale Moderation (M-27, Mockup 30): alle Kommentare der Site mit Ursprung, Autor, Datum und Status; Filter nach
 * Status, Ursprung und Suche; freigeben, als Spam markieren, antworten (als Redaktion, auf Wunsch zugleich freigeben),
 * löschen — einzeln und gesammelt. Moderieren darf, wer Inhalte bearbeiten darf (Betreiber, Administrator, Redaktion).
 * Gelöschte und markierte Kommentare verschwinden sofort aus dem Frontend und endgültig mit dem Löschlauf.
 */
final class ModerationController
{
    private const PER_PAGE = 50;

    /** @var list<string> */
    private const FILTERS = ['pending', 'approved', 'spam', 'deleted', 'all'];

    public function __construct(private readonly CurrentSite $current, private readonly Dates $dates, private readonly Recorder $recorder, private readonly SpamCounter $spam) {}

    public function index(Request $request): Response
    {
        $site = $this->site();
        $counts = Comment::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $pending = (int) ($counts[CommentStatus::Pending->value] ?? 0);
        $status = in_array($request->query('status'), self::FILTERS, true) ? (string) $request->query('status') : ($pending > 0 ? 'pending' : 'all');
        $q = trim((string) $request->query('q', ''));
        $entry = is_numeric($request->query('ursprung')) ? (int) $request->query('ursprung') : null;
        $page = $this->query($status, $q, $entry)->orderByRaw('COALESCE(parent_id, id) desc')->orderBy('id')->paginate(self::PER_PAGE)->withQueryString();
        /** @var list<Comment> $items */
        $items = $page->items();
        $origins = $this->origins($site, array_values(array_unique(array_merge(array_map(static fn (Comment $c): int => $c->entry_id, $items),
            Comment::query()->distinct()->pluck('entry_id')->map(static fn (mixed $id): int => (int) $id)->all()))));
        $parents = Comment::query()->whereKey(array_filter(array_map(static fn (Comment $c): ?int => $c->parent_id, $items)))->pluck('author_name', 'id');
        $relative = $this->dates->relativeFormatter();

        return Inertia::render('comments::Index', [
            'rows' => array_map(fn (Comment $c): array => [
                'id' => $c->id, 'author' => $c->author_name, 'initials' => CommentsRenderer::initials($c->author_name), 'email' => $c->author_email, 'website' => $c->author_url,
                'body' => $c->body, 'excerpt' => Str::limit($c->body, 280), 'created_at' => $relative($c->created_at), 'status' => $c->status->value, 'status_label' => $c->status->label(),
                'staff' => $c->user_id !== null, 'reply_to' => $c->parent_id === null ? null : ($parents[$c->parent_id] ?? '—'), 'locale' => strtoupper($c->locale),
                'origin' => $origins[$c->entry_id] ?? ['id' => $c->entry_id, 'title' => '#'.$c->entry_id, 'type' => '', 'url' => null, 'edit' => null],
                'urls' => ['status' => route('basalto.comments.status', $c->id), 'reply' => route('basalto.comments.reply', $c->id), 'destroy' => route('basalto.comments.destroy', $c->id)],
            ], $items),
            'counts' => ['pending' => $pending, 'approved' => (int) ($counts[CommentStatus::Approved->value] ?? 0), 'spam' => (int) ($counts[CommentStatus::Spam->value] ?? 0),
                'deleted' => (int) ($counts[CommentStatus::Deleted->value] ?? 0), 'all' => (int) $counts->sum()],
            'spam' => $this->spam->totals($site) + ['window' => SpamCounter::WINDOW_DAYS],
            'retention' => (int) app(Settings::class)->get('comments.retention_days', 365, $site),
            'origins' => array_values(array_map(static fn (array $o): array => ['id' => $o['id'], 'label' => $o['title']], $origins)),
            'filters' => ['status' => $status, 'q' => $q, 'ursprung' => $entry],
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total(), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'urls' => ['index' => route('basalto.comments.index'), 'bulk' => route('basalto.comments.bulk')],
        ]);
    }

    /** Freigeben, als Spam markieren oder zurück auf «wartet». */
    public function status(Request $request, int $comment): RedirectResponse
    {
        $site = $this->site();
        $item = Comment::query()->findOrFail($comment);
        $validated = $request->validate(['status' => ['required', 'in:approved,spam,pending']]);
        $this->change($site, $item, CommentStatus::from((string) $validated['status']));

        return back()->with('status', match ($item->status) {
            CommentStatus::Approved => __('Kommentar von :name freigegeben.', ['name' => $item->author_name]),
            CommentStatus::Spam => __('Kommentar von :name als Spam markiert — er erscheint nicht mehr.', ['name' => $item->author_name]),
            default => __('Kommentar von :name wartet wieder auf Freigabe.', ['name' => $item->author_name]),
        });
    }

    /** Antwort als Redaktion — immer eine Ebene: wer auf eine Antwort antwortet, hängt unter denselben Kommentar. */
    public function reply(Request $request, int $comment): RedirectResponse
    {
        $site = $this->site();
        $item = Comment::query()->findOrFail($comment);
        $validated = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000'], 'approve' => ['boolean']]);
        $user = $request->user();
        if (($validated['approve'] ?? false) === true && $item->status !== CommentStatus::Approved) {
            $this->change($site, $item, CommentStatus::Approved);
        }
        Comment::query()->create([
            'site_id' => $site->id, 'entry_id' => $item->entry_id, 'parent_id' => $item->parent_id ?? $item->id, 'user_id' => $user?->getAuthIdentifier(), 'locale' => $item->locale,
            'author_name' => (string) ($user?->getAttribute('name') ?? 'Redaktion'), 'author_email' => $user?->getAttribute('email'), 'body' => trim((string) $validated['body']),
            'status' => CommentStatus::Approved,
        ]);
        $this->changed($site);

        return back()->with('status', __('Antwort an :name veröffentlicht.', ['name' => $item->author_name]));
    }

    /** Löschen heisst: aus dem Frontend weg, endgültig nach der Frist im Löschlauf. */
    public function destroy(int $comment): RedirectResponse
    {
        $site = $this->site();
        $item = Comment::query()->findOrFail($comment);
        $this->change($site, $item, CommentStatus::Deleted);
        $this->recorder->record('comment.deleted', null, ['id' => $item->id, 'entry' => $item->entry_id], null, $site);

        return back()->with('status', __('Kommentar von :name gelöscht.', ['name' => $item->author_name]));
    }

    /** Mehrere auf einmal: freigeben, als Spam markieren oder löschen. */
    public function bulk(Request $request): RedirectResponse
    {
        $site = $this->site();
        $validated = $request->validate(['action' => ['required', 'in:approve,spam,delete'], 'ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        $target = match ($validated['action']) {
            'approve' => CommentStatus::Approved,
            'spam' => CommentStatus::Spam,
            default => CommentStatus::Deleted,
        };
        $items = Comment::query()->whereIn('id', $validated['ids'])->get();
        foreach ($items as $item) {
            $this->change($site, $item, $target);
        }
        if ($target !== CommentStatus::Approved) {
            $this->recorder->record('comment.bulk_'.$validated['action'], null, ['count' => $items->count()], null, $site);
        }

        return back()->with('status', __(':count Kommentar(e) :status.', ['count' => $items->count(), 'status' => $target->label()]));
    }

    private function change(Site $site, Comment $item, CommentStatus $status): void
    {
        if ($item->status === $status) {
            return;
        }
        if ($status === CommentStatus::Spam) {
            $this->spam->count($site->id);
        }
        $item->forceFill(['status' => $status])->save();
        $this->changed($site);
    }

    /** Sichtbares hat sich geändert: Seiten-Cache der Site und Zahl am Menü neu. */
    private function changed(Site $site): void
    {
        app(PageCache::class)->invalidate($site->id);
        Cache::forget('basalto.menu-counts.'.$site->id);
    }

    /** @return Builder<Comment> */
    private function query(string $status, string $q, ?int $entry): Builder
    {
        $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $q)).'%';

        return Comment::query()
            ->when($status !== 'all', static fn (Builder $b) => $b->where('status', $status))
            ->when($entry !== null, static fn (Builder $b) => $b->where('entry_id', $entry))
            ->when($q !== '', static fn (Builder $b) => $b->where(static fn (Builder $w) => $w->whereRaw('LOWER(author_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(author_email) LIKE ?', [$like])->orWhereRaw('LOWER(body) LIKE ?', [$like])));
    }

    /**
     * Ursprung je Inhalt: Titel (veröffentlicht in der Standardsprache, sonst die jüngste Fassung), Art aus dem Theme,
     * Adresse im Frontend und im Backend.
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, title: string, type: string, url: string|null, edit: string|null}>
     */
    private function origins(Site $site, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $types = ThemeSchema::fromTheme(app(ThemeLoader::class)->forSite($site))->types;
        } catch (Throwable) {
            $types = [];
        }
        $pages = app(Pages::class);
        $versions = EntryVersion::query()->whereIn('entry_id', $ids)->orderByDesc('number')->get(['id', 'entry_id', 'locale', 'title', 'status', 'number'])->groupBy('entry_id');
        $out = [];
        foreach (Entry::query()->withTrashed()->whereKey($ids)->get() as $entry) {
            $list = $versions->get($entry->id, collect());
            $best = $list->first(static fn (EntryVersion $v): bool => $v->locale === $site->default_locale && $v->status === EntryStatus::Published)
                ?? $list->first(static fn (EntryVersion $v): bool => $v->locale === $site->default_locale) ?? $list->first();
            $out[$entry->id] = ['id' => $entry->id, 'title' => $best instanceof EntryVersion ? $best->title : '#'.$entry->id, 'type' => (string) ($types[$entry->type]['label'] ?? $entry->type),
                'url' => $entry->deleted_at === null ? $pages->urlOf($entry, $site->default_locale) : null,
                'edit' => $entry->deleted_at === null ? route('basalto.entries.edit', [$entry->id, $best instanceof EntryVersion ? $best->locale : $site->default_locale]) : null];
        }
        uasort($out, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $out;
    }

    /** Die Site der Anfrage — Modul aus heisst 404, moderieren darf, wer Inhalte bearbeiten darf. */
    private function site(): Site
    {
        $site = $this->current->require();
        abort_unless(app(Backend::class)->moduleEnabled('comments', $site), 404);
        Gate::authorize('create', new Entry(['site_id' => $site->id]));

        return $site;
    }
}
