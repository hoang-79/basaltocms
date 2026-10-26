<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers\Extranet;

use App\Models\User;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityReport;
use Basalto\Core\Community\Conversations;
use Basalto\Core\Community\ModerationEntry;
use Basalto\Core\Community\ModerationLog;
use Basalto\Core\Community\Moderator;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Wall;
use Basalto\Core\Content\RichText;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Actions\ModerationRequests;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extranet › Community › Moderation (CR-006 § 6, FO-15): Meldungen, Freischaltung, Papierkorb, Moderationsprotokoll —
 * die Aktionen sind dieselben wie an Ort und Stelle im Forum ({@see ModerationRequests}), nur mit dem Team als
 * Moderator. Wer den Bereich «Community» hat, moderiert alle Foren.
 */
final class ModerationController
{
    public const TABS = ['meldungen', 'freischaltung', 'papierkorb', 'protokoll'];

    private const LIMIT = 50;

    public function __construct(private readonly CurrentSite $current, private readonly ModerationRequests $requests, private readonly Reports $reports) {}

    public function index(Request $request): Response
    {
        $site = CommunityController::site($this->current);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'meldungen';
        $lang = app()->getLocale();
        $base = app(Forum::class)->basePath($site, $site->default_locale);

        return Inertia::render('forum::Moderation', [
            'tab' => $tab,
            'counts' => [
                'meldungen' => $this->reports->pendingCount($site->id, [Moderation::REPORT_TYPE, Conversations::REPORT_TYPE, Wall::REPORT_TYPE]),
                'freischaltung' => Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', true)->count(),
                'papierkorb' => Thread::query()->withoutGlobalScope(SiteScope::class)->onlyTrashed()->where('site_id', $site->id)->whereNull('moved_to_id')->count()
                    + Post::query()->withoutGlobalScope(SiteScope::class)->onlyTrashed()->where('site_id', $site->id)->count(),
            ],
            'reports' => $tab === 'meldungen' ? $this->reportList($site, $lang) : [],
            'pending' => $tab === 'freischaltung' ? $this->pendingList($site, $base) : [],
            'trash' => $tab === 'papierkorb' ? $this->trashList($site) : [],
            'log' => $tab === 'protokoll' ? $this->logList($site, $request, $lang) : [],
            'filter' => ['aktion' => (string) $request->query('aktion', ''), 'konto' => (string) $request->query('konto', '')],
            'actions' => collect(Moderation::LABELS)->keys()->merge(['report.hidden', 'report.kept', 'member.warned', 'member.warning_revoked', 'member.muted', 'member.unmuted', 'member.banned', 'member.unbanned', 'member.spammer'])
                ->map(static fn (string $a): array => ['value' => $a, 'label' => app(ModerationLog::class)->label($a)])->values()->all(),
            'urls' => ['index' => ForumRoutes::url('moderation'), 'threads' => ForumRoutes::url('moderation.threads'), 'posts' => ForumRoutes::url('moderation.posts'),
                'report' => ForumRoutes::url('moderation.report'), 'overview' => ForumRoutes::url('index'), 'members' => ForumRoutes::url('members')],
        ]);
    }

    public function threads(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);

        return $this->answer($this->requests->threads($site, $this->staff($request), $request, static fn (): bool => true, app()->getLocale()));
    }

    public function posts(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);

        return $this->answer($this->requests->posts($site, $this->staff($request), $request, static fn (): bool => true, app()->getLocale()));
    }

    public function report(Request $request): RedirectResponse
    {
        $site = CommunityController::site($this->current);

        return $this->answer($this->requests->report($site, $this->staff($request), $request, static fn (): bool => true, app()->getLocale()));
    }

    /** @return list<array<string, mixed>> */
    private function reportList(Site $site, string $lang): array
    {
        $out = [];
        $groups = $this->reports->pending($site->id, [Moderation::REPORT_TYPE, Conversations::REPORT_TYPE, Wall::REPORT_TYPE], self::LIMIT);
        $front = ForumRoutes::front($site);
        $names = $this->names(array_map(static fn (array $g): ?int => $g['reports'][0]->customer_id, $groups));
        foreach ($groups as $group) {
            $about = $this->reports->describe($group['type'], $group['id']);
            $post = $group['type'] === Moderation::REPORT_TYPE ? Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->find($group['id']) : null;
            $authorNames = $this->names([$about['author'] ?? null]);
            $out[] = ['id' => $group['id'], 'type' => $group['type'], 'count' => $group['count'], 'title' => $about['title'] ?? __('(gelöscht)'), 'excerpt' => $about['excerpt'] ?? '', 'url' => $front($about['url'] ?? null),
                'place' => $about['place'] ?? '', 'author' => $authorNames[$about['author'] ?? 0] ?? __('Ehemaliges Mitglied'), 'author_id' => $about['author'] ?? null,
                'hidden' => (bool) ($post->is_disabled ?? false), 'trashed' => $post?->trashed() ?? false,
                'reports' => array_map(fn (CommunityReport $r): array => ['reason' => $this->reports->reasonLabel($group['type'], $r->reason, $lang), 'note' => $r->note,
                    'by' => $names[$r->customer_id ?? 0] ?? __('Gast'), 'date' => $r->created_at?->format('d.m.Y H:i')], $group['reports'])];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> wartende Themen (mit ihrem Startbeitrag) und Antworten, die ältesten zuerst */
    private function pendingList(Site $site, ?string $base): array
    {
        $posts = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', true)->orderBy('id')->limit(self::LIMIT)->get();
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->whereIn('id', $posts->pluck('thread_id'))->get()->keyBy('id');
        $names = $this->names($posts->pluck('customer_id')->all());
        $nodes = app(Forum::class)->nodes($site);
        $open = $this->reports->openFor(Moderation::REPORT_TYPE, array_values($posts->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));
        $front = ForumRoutes::front($site);

        return array_values($posts->map(static function (Post $p) use ($threads, $names, $nodes, $base, $open, $site, $front): array {
            $thread = $threads->get($p->thread_id);
            $isThread = $thread !== null && $thread->first_post_id === $p->id;

            return ['id' => $p->id, 'thread_id' => $thread?->id, 'kind' => $isThread ? 'thread' : 'post', 'title' => (string) $thread?->title, 'excerpt' => mb_substr(RichText::toText($p->body), 0, 240),
                'author' => $names[$p->customer_id ?? 0] ?? __('Ehemaliges Mitglied'), 'author_id' => $p->customer_id, 'date' => $p->created_at?->format('d.m.Y H:i'),
                'place' => $thread === null ? '' : ($nodes->get($thread->node_id)?->titleIn($site->default_locale) ?? ''), 'reported' => ($open[$p->id] ?? 0) > 0,
                'url' => $base === null || $thread === null ? null : $front($base.'/thema/'.$thread->pathSegment().'#beitrag-'.$p->id)];
        })->all());
    }

    /** @return list<array<string, mixed>> */
    private function trashList(Site $site): array
    {
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->onlyTrashed()->where('site_id', $site->id)->whereNull('moved_to_id')->orderByDesc('deleted_at')->limit(self::LIMIT)->get();
        $posts = Post::query()->withoutGlobalScope(SiteScope::class)->onlyTrashed()->where('site_id', $site->id)->orderByDesc('deleted_at')->limit(self::LIMIT)->get();
        $postThreads = Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', $posts->pluck('thread_id'))->get()->keyBy('id');
        $names = $this->names([...$threads->pluck('customer_id')->all(), ...$posts->pluck('customer_id')->all()]);
        $items = [];
        foreach ($threads as $t) {
            $items[] = ['kind' => 'thread', 'id' => $t->id, 'title' => $t->title, 'excerpt' => trans_choice(':count Antwort|:count Antworten', $t->replies, ['count' => $t->replies]),
                'author' => $names[$t->customer_id ?? 0] ?? __('Ehemaliges Mitglied'), 'reason' => $t->trash_reason, 'date' => $t->deleted_at?->format('d.m.Y H:i'), 'sort' => $t->deleted_at?->getTimestamp() ?? 0];
        }
        foreach ($posts as $p) {
            $items[] = ['kind' => 'post', 'id' => $p->id, 'title' => (string) $postThreads->get($p->thread_id)?->title, 'excerpt' => mb_substr(RichText::toText($p->body), 0, 240),
                'author' => $names[$p->customer_id ?? 0] ?? __('Ehemaliges Mitglied'), 'reason' => $p->trash_reason, 'date' => $p->deleted_at?->format('d.m.Y H:i'), 'sort' => $p->deleted_at?->getTimestamp() ?? 0];
        }
        usort($items, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        return array_slice($items, 0, self::LIMIT);
    }

    /** @return list<array<string, mixed>> */
    private function logList(Site $site, Request $request, string $lang): array
    {
        $action = (string) $request->query('aktion', '');
        $konto = (string) $request->query('konto', '');
        $rows = ModerationEntry::query()->withoutGlobalScopes()->where('site_id', $site->id)
            ->when($action !== '', static fn ($q) => $q->where('action', $action))
            ->when($konto !== '', static function ($q) use ($konto, $site): void {
                $ids = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('name_key', 'like', '%'.Profiles::key($konto).'%')->pluck('customer_id');
                $q->where(static fn ($w) => $w->whereIn('target_customer_id', $ids)->orWhereIn('customer_id', $ids));
            })
            ->orderByDesc('id')->limit(100)->get();
        $names = $this->names([...$rows->pluck('customer_id')->all(), ...$rows->pluck('target_customer_id')->all()]);
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->pluck('name', 'id');
        $log = app(ModerationLog::class);

        return array_values($rows->map(static fn (ModerationEntry $e): array => ['id' => $e->id, 'date' => $e->created_at?->format('d.m.Y H:i'), 'action' => $log->label($e->action, $lang),
            'subject' => $e->subject_label, 'reason' => $e->reason,
            'by' => $e->user_id !== null ? __('Team: :name', ['name' => (string) ($users[$e->user_id] ?? '?')]) : ($e->customer_id !== null ? ($names[$e->customer_id] ?? '?') : __('automatisch')),
            'target' => $e->target_customer_id === null ? null : ($names[$e->target_customer_id] ?? __('Ehemaliges Mitglied'))])->all());
    }

    /**
     * @param  array<int, int|null>  $customerIds
     * @return array<int, string>
     */
    private function names(array $customerIds): array
    {
        $ids = array_values(array_filter(array_unique($customerIds), static fn (?int $id): bool => $id !== null));

        return $ids === [] ? [] : CommunityProfile::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->pluck('name', 'customer_id')->map(static fn (mixed $n): string => (string) $n)->all();
    }

    private function staff(Request $request): Moderator
    {
        $user = $request->user();
        assert($user instanceof User);

        return Moderator::staff($user);
    }

    /** @param  array{ok: bool, message: string}  $result */
    private function answer(array $result): RedirectResponse
    {
        return $result['ok'] ? back()->with('status', $result['message']) : back()->withErrors(['moderation' => $result['message']]);
    }
}
