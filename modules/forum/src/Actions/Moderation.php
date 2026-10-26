<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Actions;

use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\CommunityAttachment;
use Basalto\Core\Community\CommunityReport;
use Basalto\Core\Community\ModerationLog;
use Basalto\Core\Community\Moderator;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Counters;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumLive;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Poll;
use Basalto\Modules\Forum\Models\PollOption;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\NodeType;
use Basalto\Modules\Forum\Notifier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Die Moderation des Forums (CR-006 FO-15, FO-16, FO-17) — **eine** Klasse für beide Wege: an Ort und Stelle im
 * Frontend (moderierendes Kundenkonto) und in der Liste im Extranet (Team). Jede Aktion schreibt dieselbe Zeile ins
 * Moderationsprotokoll und rechnet die Zähler neu. Die Rechte prüft der Aufrufer (`Forum::MODERATE` bzw. der Bereich
 * «Community» im Extranet).
 *
 * - Thema: schliessen/öffnen, anheften/lösen, Ankündigung, verschieben (mit Verweis, der abläuft), zusammenführen,
 *   Papierkorb, wiederherstellen, endgültig löschen;
 * - Beiträge: Papierkorb, wiederherstellen, endgültig löschen, freischalten, ausblenden, in ein neues Thema teilen;
 * - Meldungen entscheiden, Spammer mit einem Klick entfernen.
 */
final class Moderation
{
    public const REPORT_TYPE = Forum::ATTACHMENTS;

    /** Bezeichnungen fürs Protokoll */
    public const LABELS = [
        'forum.thread.closed' => 'Thema geschlossen',
        'forum.thread.opened' => 'Thema geöffnet',
        'forum.thread.stuck' => 'Thema angeheftet',
        'forum.thread.unstuck' => 'Thema gelöst',
        'forum.thread.announced' => 'Als Ankündigung gesetzt',
        'forum.thread.unannounced' => 'Ankündigung aufgehoben',
        'forum.thread.featured' => 'Thema empfohlen',
        'forum.thread.unfeatured' => 'Empfehlung aufgehoben',
        'forum.thread.moved' => 'Thema verschoben',
        'forum.thread.copied' => 'Thema kopiert',
        'forum.thread.merged' => 'Themen zusammengeführt',
        'forum.thread.trashed' => 'Thema in den Papierkorb',
        'forum.thread.restored' => 'Thema wiederhergestellt',
        'forum.thread.deleted' => 'Thema endgültig gelöscht',
        'forum.thread.approved' => 'Thema freigeschaltet',
        'forum.post.trashed' => 'Beitrag in den Papierkorb',
        'forum.post.restored' => 'Beitrag wiederhergestellt',
        'forum.post.deleted' => 'Beitrag endgültig gelöscht',
        'forum.post.approved' => 'Beitrag freigeschaltet',
        'forum.post.disabled' => 'Beitrag ausgeblendet',
        'forum.post.split' => 'Beiträge in ein neues Thema geteilt',
        'forum.post.auto_hidden' => 'Beitrag nach Meldungen vorläufig ausgeblendet',
    ];

    public function __construct(private readonly ModerationLog $log, private readonly Counters $counters, private readonly Reports $reports, private readonly Notifier $notifier) {}

    // ── Thema ───────────────────────────────────────────────────

    /** Schliessen, Anheften, Ankündigung, Empfehlen — `flag` ist `closed`, `sticky`, `announcement` oder `featured`. */
    public function flag(Site $site, Moderator $by, Thread $thread, string $flag, bool $on): void
    {
        [$column, $action] = match ($flag) {
            'closed' => ['is_closed', $on ? 'forum.thread.closed' : 'forum.thread.opened'],
            'sticky' => ['is_sticky', $on ? 'forum.thread.stuck' : 'forum.thread.unstuck'],
            'announcement' => ['is_announcement', $on ? 'forum.thread.announced' : 'forum.thread.unannounced'],
            'featured' => ['is_featured', $on ? 'forum.thread.featured' : 'forum.thread.unfeatured'],
            default => throw new InvalidArgumentException('Unbekannte Markierung: '.$flag),
        };
        if ((bool) $thread->getAttribute($column) === $on) {
            return;
        }
        $thread->forceFill([$column => $on])->saveQuietly();
        $this->logThread($site, $by, $action, $thread);
        $this->counters->recount($site);
    }

    /**
     * Ein Thema in ein anderes Forum verschieben; auf Wunsch bleibt im alten Forum ein Verweis stehen, der nach der
     * eingestellten Zahl Tage (`forum.redirect_days`, Vorgabe 7) verschwindet.
     */
    public function move(Site $site, Moderator $by, Thread $thread, Node $target, bool $leaveRedirect = true): void
    {
        if ($target->type !== NodeType::Board || $target->site_id !== $site->id || $thread->node_id === $target->id || $thread->isRedirect()) {
            return;
        }
        $from = $thread->node_id;
        DB::transaction(function () use ($site, $thread, $target, $leaveRedirect, $from): void {
            $thread->forceFill(['node_id' => $target->id])->saveQuietly();
            if ($leaveRedirect) {
                $days = max(0, (int) app(Settings::class)->get('forum.redirect_days', 7, $site));
                $redirect = new Thread(['site_id' => $site->id, 'node_id' => $from, 'customer_id' => $thread->customer_id, 'title' => $thread->title, 'slug' => $thread->slug, 'locale' => $thread->locale]);
                $redirect->forceFill(['moved_to_id' => $thread->id, 'redirect_until' => $days > 0 ? now()->addDays($days) : null, 'last_posted_at' => $thread->last_posted_at,
                    'last_customer_id' => $thread->last_customer_id, 'replies' => $thread->replies])->save();
            }
        });
        $this->logThread($site, $by, 'forum.thread.moved', $thread, data: ['von' => $from, 'nach' => $target->id, 'verweis' => $leaveRedirect]);
        $this->counters->recount($site, [$thread->id]);
    }

    /**
     * Ein Thema kopieren (CR-006 § 3.2): eine neue Fassung im Ziel-Forum mit Titel, Präfix (wenn das Ziel es anbietet),
     * Themenfeldern, Schlagwörtern, Umfrage (ohne Stimmen) und allen sichtbaren Beiträgen in ihrer Reihenfolge — mit
     * den ursprünglichen Verfasserinnen und Zeiten. Reaktionen, Anhänge und Meldungen bleiben beim Original.
     */
    public function copy(Site $site, Moderator $by, Thread $thread, Node $target): Thread
    {
        $copy = DB::transaction(function () use ($site, $thread, $target): Thread {
            $prefixes = array_map('intval', (array) $target->setting('prefixes', []));
            $copy = new Thread(['site_id' => $site->id, 'node_id' => $target->id, 'customer_id' => $thread->customer_id, 'title' => $thread->title, 'slug' => $thread->slug,
                'locale' => $thread->locale, 'prefix_id' => in_array($thread->prefix_id, $prefixes, true) ? $thread->prefix_id : null, 'form_values' => $thread->form_values]);
            $copy->forceFill(['created_at' => $thread->created_at, 'is_closed' => $thread->is_closed])->save();
            $first = null;
            foreach (Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_disabled', false)->orderBy('id')->get() as $post) {
                $new = new Post(['site_id' => $site->id, 'thread_id' => $copy->id, 'customer_id' => $post->customer_id, 'body' => $post->body]);
                $new->forceFill(['is_official' => $post->is_official, 'created_at' => $post->created_at, 'updated_at' => $post->created_at])->save();
                $first ??= $new;
            }
            $copy->forceFill(['first_post_id' => $first?->id])->saveQuietly();
            $tags = DB::table('forum_thread_tag')->where('thread_id', $thread->id)->pluck('tag_id');
            foreach ($tags as $tag) {
                DB::table('forum_thread_tag')->insert(['thread_id' => $copy->id, 'tag_id' => $tag]);
            }
            $poll = Poll::query()->withoutGlobalScopes()->where('thread_id', $thread->id)->first();
            if ($poll !== null) {
                $newPoll = Poll::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'thread_id' => $copy->id, 'question' => $poll->question, 'max_choices' => $poll->max_choices,
                    'results_after_vote' => $poll->results_after_vote, 'ends_at' => $poll->ends_at]);
                foreach ($poll->options()->get() as $option) {
                    PollOption::query()->create(['poll_id' => $newPoll->id, 'label' => $option->label, 'position' => $option->position]);
                }
            }

            return $copy;
        });
        $this->logThread($site, $by, 'forum.thread.copied', $copy, data: ['von' => $thread->id, 'nach' => $target->id]);
        $this->counters->recount($site, [$copy->id], $this->authors($copy));

        return $copy->refresh();
    }

    /**
     * Themen in ein Ziel zusammenführen: alle Beiträge ziehen um, die Quellen werden zu dauerhaften Verweisen (alte
     * Adressen führen weiter), Abos ziehen mit.
     *
     * @param  list<Thread>  $sources
     */
    public function merge(Site $site, Moderator $by, Thread $target, array $sources): void
    {
        $sources = array_values(array_filter($sources, static fn (Thread $t): bool => $t->id !== $target->id && ! $t->isRedirect() && $t->site_id === $site->id));
        if ($sources === []) {
            return;
        }
        $ids = array_map(static fn (Thread $t): int => $t->id, $sources);
        DB::transaction(function () use ($target, $ids): void {
            Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('thread_id', $ids)->update(['thread_id' => $target->id]);
            DB::table('forum_polls')->whereIn('thread_id', $ids)->delete();
            foreach (DB::table('community_subscriptions')->where('subject_type', 'forum_thread')->whereIn('subject_id', $ids)->get() as $row) {
                DB::table('community_subscriptions')->where('id', $row->id)->delete();
                DB::table('community_subscriptions')->insertOrIgnore(['site_id' => $row->site_id, 'customer_id' => $row->customer_id, 'subject_type' => 'forum_thread',
                    'subject_id' => $target->id, 'mode' => $row->mode, 'created_at' => $row->created_at]);
            }
            Thread::query()->withoutGlobalScope(SiteScope::class)->whereIn('id', $ids)->update(['moved_to_id' => $target->id, 'redirect_until' => null, 'is_sticky' => false,
                'is_announcement' => false, 'deleted_at' => now()]);
        });
        $this->logThread($site, $by, 'forum.thread.merged', $target, data: ['quellen' => $ids, 'titel' => array_map(static fn (Thread $t): string => $t->title, $sources)]);
        $this->counters->recount($site, [$target->id], array_map(static fn (Thread $t): ?int => $t->customer_id, $sources));
    }

    /** Ein Thema in den Papierkorb — es verschwindet für alle ausser der Moderation; wiederherstellen geht. */
    public function trashThread(Site $site, Moderator $by, Thread $thread, ?string $reason = null): void
    {
        if ($thread->trashed()) {
            return;
        }
        $thread->forceFill(['trash_reason' => $this->reason($reason)])->saveQuietly();
        $thread->delete();
        $this->resolveThreadReports($site, $by, $thread, CommunityReport::HIDDEN);
        $this->logThread($site, $by, 'forum.thread.trashed', $thread, $reason);
        $this->counters->recount($site, [$thread->id], $this->authors($thread));
    }

    public function restoreThread(Site $site, Moderator $by, Thread $thread): void
    {
        if (! $thread->trashed() || $thread->isRedirect()) {
            return;
        }
        $thread->restore();
        $thread->forceFill(['trash_reason' => null])->saveQuietly();
        $this->logThread($site, $by, 'forum.thread.restored', $thread);
        $this->counters->recount($site, [$thread->id], $this->authors($thread));
    }

    /** Endgültig löschen — nur aus dem Papierkorb. Beiträge, Umfrage und Anhänge gehen mit. */
    public function destroyThread(Site $site, Moderator $by, Thread $thread): void
    {
        if (! $thread->trashed()) {
            return;
        }
        $authors = $this->authors($thread);
        $postIds = array_values(Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('thread_id', $thread->id)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->logThread($site, $by, 'forum.thread.deleted', $thread, data: ['beitraege' => count($postIds)]);
        $this->forgetPosts($postIds);
        Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('moved_to_id', $thread->id)->forceDelete();
        $thread->forceDelete();
        $this->counters->recount($site, [], $authors);
    }

    /** Ein wartendes Thema (Freischaltung) freigeben — mit seinem Startbeitrag; erst jetzt gehen Benachrichtigungen raus. */
    public function approveThread(Site $site, Moderator $by, Thread $thread): void
    {
        if (! $thread->is_disabled) {
            return;
        }
        $thread->forceFill(['is_disabled' => false])->saveQuietly();
        $first = Post::query()->withoutGlobalScope(SiteScope::class)->find($thread->first_post_id);
        if ($first !== null && $first->is_disabled) {
            $first->forceFill(['is_disabled' => false])->saveQuietly();
        }
        $this->resolveThreadReports($site, $by, $thread, CommunityReport::KEPT);
        $this->logThread($site, $by, 'forum.thread.approved', $thread);
        $this->counters->recount($site, [$thread->id], [$thread->customer_id]);
        app(ForumLive::class)->posted($site, $thread);
        $author = $thread->customer_id === null ? null : Customer::query()->withoutGlobalScopes()->find($thread->customer_id);
        if ($author !== null && $first !== null) {
            $this->notifier->posted($site, $author, $thread->refresh(), $first, true);
        }
    }

    // ── Beiträge ────────────────────────────────────────────────

    /** Ein Beitrag in den Papierkorb — der Startbeitrag nimmt das ganze Thema mit. */
    public function trashPost(Site $site, Moderator $by, Post $post, ?string $reason = null): void
    {
        $thread = $this->threadOf($post);
        if ($thread === null || $post->trashed()) {
            return;
        }
        if ($thread->first_post_id === $post->id) {
            $this->trashThread($site, $by, $thread, $reason);

            return;
        }
        $post->forceFill(['trash_reason' => $this->reason($reason)])->saveQuietly();
        $post->delete();
        $this->reports->resolve(self::REPORT_TYPE, $post->id, CommunityReport::HIDDEN, $by);
        $this->logPost($site, $by, 'forum.post.trashed', $post, $thread, $reason);
        $this->counters->recount($site, [$thread->id], [$post->customer_id]);
    }

    public function restorePost(Site $site, Moderator $by, Post $post): void
    {
        $thread = $this->threadOf($post);
        if ($thread === null || ! $post->trashed()) {
            return;
        }
        $post->restore();
        $post->forceFill(['trash_reason' => null])->saveQuietly();
        $this->logPost($site, $by, 'forum.post.restored', $post, $thread);
        $this->counters->recount($site, [$thread->id], [$post->customer_id]);
    }

    public function destroyPost(Site $site, Moderator $by, Post $post): void
    {
        $thread = $this->threadOf($post);
        if ($thread === null || ! $post->trashed() || $thread->first_post_id === $post->id) {
            return;
        }
        $this->logPost($site, $by, 'forum.post.deleted', $post, $thread);
        $this->forgetPosts([$post->id]);
        $this->counters->recount($site, [$thread->id], [$post->customer_id]);
    }

    /** Freischalten: ein wartender oder nach Meldungen ausgeblendeter Beitrag erscheint (wieder). */
    public function approvePost(Site $site, Moderator $by, Post $post): void
    {
        $thread = $this->threadOf($post);
        if ($thread === null || ! $post->is_disabled) {
            return;
        }
        if ($thread->first_post_id === $post->id && $thread->is_disabled) {
            $this->approveThread($site, $by, $thread);

            return;
        }
        $wasReported = $this->reports->open(self::REPORT_TYPE, $post->id) > 0;
        $post->forceFill(['is_disabled' => false])->saveQuietly();
        $this->reports->resolve(self::REPORT_TYPE, $post->id, CommunityReport::KEPT, $by);
        $this->logPost($site, $by, 'forum.post.approved', $post, $thread);
        $this->counters->recount($site, [$thread->id], [$post->customer_id]);
        app(ForumLive::class)->posted($site, $thread);
        $author = $post->customer_id === null ? null : Customer::query()->withoutGlobalScopes()->find($post->customer_id);
        if ($author !== null && ! $wasReported && $post->created_at !== null && $post->created_at->gt(now()->subDays(7))) {
            $this->notifier->posted($site, $author, $thread->refresh(), $post, false);
        }
    }

    /** Ausblenden bis zur Entscheidung (die Verfasserin sieht ihn nicht mehr, die Moderation schon). */
    public function disablePost(Site $site, Moderator $by, Post $post, string $action = 'forum.post.disabled', ?string $reason = null): void
    {
        $thread = $this->threadOf($post);
        if ($thread === null || $post->is_disabled || $thread->first_post_id === $post->id && $thread->is_disabled) {
            return;
        }
        $post->forceFill(['is_disabled' => true])->saveQuietly();
        if ($thread->first_post_id === $post->id) {
            $thread->forceFill(['is_disabled' => true])->saveQuietly();
        }
        $this->logPost($site, $by, $action, $post, $thread, $reason);
        $this->counters->recount($site, [$thread->id], [$post->customer_id]);
    }

    /** TB 52 (CR-009 Nr. 10): Bearbeiten eines Beitrags sperren oder wieder erlauben — die Moderation darf es immer. */
    public function lockPostEditing(Site $site, Moderator $by, Post $post, bool $lock): void
    {
        $thread = $this->threadOf($post);
        if ($thread === null || $post->is_edit_locked === $lock) {
            return;
        }
        $post->forceFill(['is_edit_locked' => $lock])->saveQuietly();
        $this->logPost($site, $by, $lock ? 'forum.post.edit_locked' : 'forum.post.edit_unlocked', $post, $thread, null);
    }

    /**
     * Beiträge in ein neues Thema teilen — nicht den Startbeitrag. Das neue Thema übernimmt den ersten der Beiträge
     * als Startbeitrag, im selben oder einem anderen Forum.
     *
     * @param  list<Post>  $posts
     */
    public function split(Site $site, Moderator $by, Thread $from, array $posts, string $title, ?Node $node = null): ?Thread
    {
        $posts = array_values(array_filter($posts, static fn (Post $p): bool => $p->thread_id === $from->id && $p->id !== $from->first_post_id));
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($posts === [] || mb_strlen($title) < 3) {
            return null;
        }
        usort($posts, static fn (Post $a, Post $b): int => $a->id <=> $b->id);
        $node ??= Node::query()->withoutGlobalScopes()->findOrFail($from->node_id);
        $thread = DB::transaction(function () use ($site, $from, $posts, $title, $node): Thread {
            $first = $posts[0];
            $thread = new Thread(['site_id' => $site->id, 'node_id' => $node->id, 'customer_id' => $first->customer_id, 'title' => mb_substr($title, 0, 120),
                'slug' => Posting::slug($title), 'locale' => $from->locale]);
            $thread->save();
            Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', array_map(static fn (Post $p): int => $p->id, $posts))->update(['thread_id' => $thread->id]);
            $thread->forceFill(['first_post_id' => $first->id, 'created_at' => $first->created_at])->saveQuietly();

            return $thread;
        });
        $this->logThread($site, $by, 'forum.post.split', $thread, data: ['aus' => $from->id, 'beitraege' => array_map(static fn (Post $p): int => $p->id, $posts)]);
        $this->counters->recount($site, [$from->id, $thread->id]);

        return $thread->refresh();
    }

    // ── Meldungen, Spammer ──────────────────────────────────────

    /** Eine Meldung zu einem Beitrag entscheiden: `hidden` legt ihn in den Papierkorb, `kept` lässt (oder macht) ihn sichtbar. */
    public function decideReport(Site $site, Moderator $by, Post $post, string $decision): void
    {
        if ($decision === CommunityReport::HIDDEN) {
            $this->trashPost($site, $by, $post, __('Nach Meldung'));
            $this->reports->resolve(self::REPORT_TYPE, $post->id, CommunityReport::HIDDEN, $by);
            $this->log->record($site, 'report.hidden', $by, self::REPORT_TYPE, $post->id, $this->threadOf($post)?->title, $post->customer_id);

            return;
        }
        if ($post->is_disabled) {
            $this->approvePost($site, $by, $post);
        }
        $this->reports->resolve(self::REPORT_TYPE, $post->id, CommunityReport::KEPT, $by);
        $this->log->record($site, 'report.kept', $by, self::REPORT_TYPE, $post->id, $this->threadOf($post)?->title, $post->customer_id);
    }

    /** Vorläufig ausblenden nach Meldungen (aus dem Kern, ab der Schwelle). */
    public function autoHide(Post $post, int $open): void
    {
        $site = Site::query()->find($post->site_id);
        if ($site !== null && ! $post->is_disabled) {
            $this->disablePost($site, Moderator::system(), $post, 'forum.post.auto_hidden', (string) __('Vorläufig ausgeblendet nach :count Meldungen — wartet auf die Durchsicht.', ['count' => $open]));
        }
    }

    /**
     * «Als Spammer entfernen» (FO-17): Konto ohne Ende sperren, alle Themen und Beiträge in den Papierkorb, offene
     * Meldungen zu ihnen als «ausgeblendet» erledigen, Zähler neu.
     *
     * @return array{threads: int, posts: int}
     */
    public function removeSpammer(Site $site, Moderator $by, Customer $customer): array
    {
        app(Sanctions::class)->ban($site, $customer, $by, null, __('Als Spammer entfernt'));
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('customer_id', $customer->id)->whereNull('moved_to_id')->get();
        $posts = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('customer_id', $customer->id)->get();
        $reason = (string) __('Als Spammer entfernt');
        DB::transaction(function () use ($threads, $posts, $reason, $by): void {
            foreach ($threads as $thread) {
                $thread->forceFill(['trash_reason' => $reason])->saveQuietly();
                $thread->delete();
            }
            foreach ($posts as $post) {
                $post->forceFill(['trash_reason' => $reason])->saveQuietly();
                $post->delete();
                $this->reports->resolve(self::REPORT_TYPE, $post->id, CommunityReport::HIDDEN, $by);
            }
        });
        // Abos und Reaktionen des Kontos zählen nicht mehr
        DB::table('community_reactions')->where('customer_id', $customer->id)->where('subject_type', Forum::ATTACHMENTS)->delete();
        $name = app(Sanctions::class)->profile($customer)->name ?? $customer->email;
        $this->log->record($site, 'member.spammer', $by, 'customer', $customer->id, $name, $customer->id, null, ['themen' => $threads->count(), 'beitraege' => $posts->count()]);
        $affected = [...$threads->pluck('id')->all(), ...$posts->pluck('thread_id')->all()];
        $this->counters->recount($site, array_values(array_map('intval', array_unique($affected))), [$customer->id]);
        // Reaktionszähler der Beiträge anderer nachführen
        foreach (Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('reactions', '>', 0)->get(['id', 'reactions']) as $other) {
            $count = DB::table('community_reactions')->where('subject_type', Forum::ATTACHMENTS)->where('subject_id', $other->id)->count();
            if ($count !== $other->reactions) {
                Post::query()->withoutGlobalScope(SiteScope::class)->whereKey($other->id)->update(['reactions' => $count]);
            }
        }

        return ['threads' => $threads->count(), 'posts' => $posts->count()];
    }

    // ── Hilfen ──────────────────────────────────────────────────

    private function threadOf(Post $post): ?Thread
    {
        return Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->find($post->thread_id);
    }

    /** @return list<int|null> alle, die im Thema geschrieben haben */
    private function authors(Thread $thread): array
    {
        return array_values(Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('thread_id', $thread->id)->distinct()->pluck('customer_id')
            ->map(static fn (mixed $id): ?int => $id === null ? null : (int) $id)->all());
    }

    private function resolveThreadReports(Site $site, Moderator $by, Thread $thread, string $decision): void
    {
        if ($thread->first_post_id !== null) {
            $this->reports->resolve(self::REPORT_TYPE, $thread->first_post_id, $decision, $by);
        }
    }

    /** @param  list<int>  $postIds */
    private function forgetPosts(array $postIds): void
    {
        if ($postIds === []) {
            return;
        }
        DB::table('community_reactions')->where('subject_type', Forum::ATTACHMENTS)->whereIn('subject_id', $postIds)->delete();
        DB::table('community_reports')->where('subject_type', self::REPORT_TYPE)->whereIn('subject_id', $postIds)->delete();
        foreach (CommunityAttachment::query()->withoutGlobalScopes()->where('subject_type', Forum::ATTACHMENTS)->whereIn('subject_id', $postIds)->get() as $attachment) {
            app(Attachments::class)->delete($attachment);
        }
        DB::table('forum_post_revisions')->whereIn('post_id', $postIds)->delete();
        Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', $postIds)->forceDelete();
    }

    private function reason(?string $reason): ?string
    {
        return $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500);
    }

    /** @param  array<string, mixed>  $data */
    private function logThread(Site $site, Moderator $by, string $action, Thread $thread, ?string $reason = null, array $data = []): void
    {
        $this->log->record($site, $action, $by, 'forum_thread', $thread->id, $thread->title, $thread->customer_id, $reason, $data);
    }

    private function logPost(Site $site, Moderator $by, string $action, Post $post, Thread $thread, ?string $reason = null): void
    {
        $this->log->record($site, $action, $by, self::REPORT_TYPE, $post->id, $thread->title, $post->customer_id, $reason, ['thema' => $thread->id]);
    }
}
