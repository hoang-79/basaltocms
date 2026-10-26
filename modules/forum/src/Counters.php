<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Die Zähler des Forums neu rechnen (CR-006 FO-16, Risiko «Zähler laufen auseinander»): nach jeder Moderationsaktion
 * für die betroffenen Themen und Konten, für die Foren immer die ganze Site (ein Foren-Baum ist klein). Sichtbar ist,
 * was weder wartet (`is_disabled`) noch im Papierkorb liegt; Verweis-Themen zählen nicht. Auch als Befehl
 * `basalto:forum-recount`.
 */
final class Counters
{
    public function __construct(private readonly Forum $forum) {}

    /**
     * @param  list<int>  $threadIds
     * @param  list<int|null>  $customerIds
     */
    public function recount(Site $site, array $threadIds = [], array $customerIds = []): void
    {
        foreach (array_unique($threadIds) as $id) {
            $this->thread($id);
        }
        $this->nodes($site);
        $this->profiles($site, array_values(array_filter(array_unique($customerIds), static fn (?int $id): bool => $id !== null)));
        $this->forum->forget();
        app(PageCache::class)->invalidate($site->id);
    }

    /** Alles einer Site — für den Befehl. */
    public function all(Site $site): void
    {
        $threads = array_values(Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNull('moved_to_id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $customers = array_values(CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->pluck('customer_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->recount($site, $threads, $customers);
    }

    public function thread(int $id): void
    {
        $thread = Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->find($id);
        if ($thread === null || $thread->isRedirect()) {
            app(ForumSearch::class)->thread($id);

            return;
        }
        $visible = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $id)->where('is_disabled', false);
        $first = (clone $visible)->orderBy('id')->first();
        $last = (clone $visible)->orderByDesc('id')->first();
        $count = (clone $visible)->count();
        $thread->forceFill([
            'first_post_id' => $first->id ?? $thread->first_post_id, 'last_post_id' => $last?->id, 'last_customer_id' => $last?->customer_id,
            'last_posted_at' => $last?->created_at, 'replies' => max(0, $count - 1),
            'best_post_id' => $thread->best_post_id !== null && (clone $visible)->whereKey($thread->best_post_id)->exists() ? $thread->best_post_id : null,
        ]);
        if ($thread->best_post_id === null) {
            $thread->is_done = false;
        }
        if ($thread->isDirty()) {
            $thread->saveQuietly();
        }
        // TB 41: die Suche kennt den neuen Stand
        app(ForumSearch::class)->thread($thread->id);
    }

    /** Themen, Beiträge und letzter Beitrag je Forum — mit allen Unterforen. */
    public function nodes(Site $site): void
    {
        $direct = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')
            ->selectRaw('node_id, count(*) as threads, sum(replies) as replies, max(last_post_id) as last_post')->groupBy('node_id')->get()
            ->keyBy('node_id');
        $nodes = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('id');
        $totals = [];
        foreach ($nodes as $node) {
            $row = $direct->get($node->id);
            $threads = (int) ($row?->getAttribute('threads') ?? 0);
            $own = ['threads' => $threads, 'posts' => $threads + (int) ($row?->getAttribute('replies') ?? 0), 'last' => $row?->getAttribute('last_post') === null ? null : (int) $row->getAttribute('last_post')];
            // zum Knoten und zu allen Vorfahren addieren
            for ($current = $node, $guard = 0; $current !== null && $guard < 50; $current = $current->parent_id === null ? null : $nodes->get($current->parent_id), $guard++) {
                $sum = $totals[$current->id] ?? ['threads' => 0, 'posts' => 0, 'last' => null];
                $totals[$current->id] = ['threads' => $sum['threads'] + $own['threads'], 'posts' => $sum['posts'] + $own['posts'], 'last' => max($sum['last'] ?? 0, $own['last'] ?? 0) ?: null];
            }
        }
        foreach ($nodes as $node) {
            $sum = $totals[$node->id] ?? ['threads' => 0, 'posts' => 0, 'last' => null];
            if ($node->threads_count !== $sum['threads'] || $node->posts_count !== $sum['posts'] || $node->last_post_id !== $sum['last']) {
                Node::query()->withoutGlobalScopes()->whereKey($node->id)->update(['threads_count' => $sum['threads'], 'posts_count' => $sum['posts'], 'last_post_id' => $sum['last'], 'updated_at' => now()]);
            }
        }
    }

    /**
     * Beitragszahl am Profil: sichtbare Beiträge in sichtbaren Themen.
     *
     * @param  list<int>  $customerIds
     */
    public function profiles(Site $site, array $customerIds): void
    {
        foreach ($customerIds as $customerId) {
            $posts = DB::table('forum_posts')->join('forum_threads', 'forum_threads.id', '=', 'forum_posts.thread_id')
                ->where('forum_posts.site_id', $site->id)->where('forum_posts.customer_id', $customerId)
                ->where('forum_posts.is_disabled', false)->whereNull('forum_posts.deleted_at')
                ->where('forum_threads.is_disabled', false)->whereNull('forum_threads.deleted_at')->count();
            CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('customer_id', $customerId)->update(['posts' => $posts]);
        }
    }
}
