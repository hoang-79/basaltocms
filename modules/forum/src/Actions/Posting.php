<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Actions;

use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\Flood;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Subscriptions;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumLive;
use Basalto\Modules\Forum\ForumSearch;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Poll;
use Basalto\Modules\Forum\Models\PollOption;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Revision;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\Notifier;
use Basalto\Modules\Forum\ReadTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Schreiben im Forum (CR-006 FO-05): Thema eröffnen und antworten. Frontend und Extranet rufen dieselben Aktionen —
 * die Rechte prüft der Aufrufer mit `Forum::canStart` / `Forum::canReply`. Zähler (Forum, Thema, Profil) und «letzter
 * Beitrag» werden hier gepflegt, in einer Transaktion; danach ist der Seiten-Cache der Site veraltet.
 */
final class Posting
{
    public function __construct(private readonly Profiles $profiles, private readonly Forum $forum, private readonly Notifier $notifier, private readonly Subscriptions $subscriptions,
        private readonly ReadTracker $reads) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $values  Werte der Themenfelder
     * @param  list<int>  $attachments  hochgeladene Anhänge der Person
     * @param  array{question: string, options: list<string>, max_choices: int, ends_at: \DateTimeInterface|null, results_after_vote: bool}|null  $poll
     */
    public function startThread(Site $site, Customer $customer, Node $node, string $title, array $body, string $locale, ?string $ipHash = null,
        ?int $prefixId = null, array $values = [], array $attachments = [], bool $official = false, ?array $poll = null, bool $pending = false): Thread
    {
        $thread = DB::transaction(function () use ($site, $customer, $node, $title, $body, $locale, $ipHash, $prefixId, $values, $attachments, $official, $poll, $pending): Thread {
            $title = trim((string) preg_replace('/\s+/u', ' ', $title));
            $thread = new Thread(['site_id' => $site->id, 'node_id' => $node->id, 'customer_id' => $customer->id, 'title' => $title,
                'slug' => self::slug($title), 'locale' => $locale, 'prefix_id' => $prefixId, 'form_values' => $values === [] ? null : $values, 'is_disabled' => $pending]);
            $thread->save();
            $post = $this->createPost($site, $customer, $thread, $body, $ipHash, $attachments, $official, $pending);
            if ($poll !== null) {
                $model = Poll::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'thread_id' => $thread->id, 'question' => $poll['question'],
                    'max_choices' => $poll['max_choices'], 'results_after_vote' => $poll['results_after_vote'], 'ends_at' => $poll['ends_at']]);
                foreach ($poll['options'] as $i => $label) {
                    PollOption::query()->create(['poll_id' => $model->id, 'label' => $label, 'position' => $i]);
                }
            }
            $thread->forceFill(['first_post_id' => $post->id, 'last_post_id' => $post->id, 'last_customer_id' => $customer->id, 'last_posted_at' => $post->created_at])->save();
            if (! $pending) {
                $this->bumpNode($node, $post, threads: 1);
            }

            return $thread;
        });
        $this->after($site);
        if (! $pending) {
            app(ForumLive::class)->posted($site, $thread);
        }
        $post = Post::query()->withoutGlobalScope(SiteScope::class)->findOrFail($thread->first_post_id);
        $this->afterPosting($site, $customer, $thread, $post, true, $pending);
        app(ForumSearch::class)->thread($thread->id);

        return $thread;
    }

    /**
     * Antworten; wartet die Antwort auf die Freischaltung (TB 39), zählt sie erst danach.
     *
     * @param  array<string, mixed>  $body
     * @param  list<int>  $attachments
     */
    public function reply(Site $site, Customer $customer, Thread $thread, array $body, ?string $ipHash = null, array $attachments = [], bool $official = false, bool $pending = false): Post
    {
        $post = DB::transaction(function () use ($site, $customer, $thread, $body, $ipHash, $attachments, $official, $pending): Post {
            $post = $this->createPost($site, $customer, $thread, $body, $ipHash, $attachments, $official, $pending);
            if ($pending) {
                return $post;
            }
            Thread::query()->withoutGlobalScope(SiteScope::class)->whereKey($thread->id)->increment('replies', 1, [
                'last_post_id' => $post->id, 'last_customer_id' => $customer->id, 'last_posted_at' => $post->created_at,
            ]);
            $node = Node::query()->withoutGlobalScopes()->findOrFail($thread->node_id);
            $this->bumpNode($node, $post);

            return $post;
        });
        $this->after($site);
        if (! $pending) {
            app(ForumLive::class)->posted($site, $thread);
        }
        $this->afterPosting($site, $customer, $thread, $post, false, $pending);
        app(ForumSearch::class)->thread($thread->id);

        return $post;
    }

    /**
     * Die hilfreichste Antwort setzen oder (null) lösen — das Thema gilt dann als erledigt (FO-12).
     */
    public function markBest(Site $site, Customer $by, Thread $thread, ?Post $post): void
    {
        $thread->forceFill(['best_post_id' => $post?->id, 'is_done' => $post !== null])->save();
        $this->after($site);
        if ($post !== null) {
            $this->notifier->bestAnswer($site, $by, $thread, $post);
        }
    }

    /** Nach dem Schreiben: selbst gelesen, auf Wunsch abonniert, die anderen benachrichtigt (wartende Beiträge erst beim Freischalten). */
    private function afterPosting(Site $site, Customer $customer, Thread $thread, Post $post, bool $isNewThread, bool $pending = false): void
    {
        app(Flood::class)->hit($site, $customer);
        $this->reads->markThread($customer, $thread);
        if ((bool) data_get($customer->preferences, 'community.auto_watch', true)) {
            $this->subscriptions->watchUnlessSet($customer, 'forum_thread', $thread->id);
        }
        if (! $pending) {
            $this->notifier->posted($site, $customer, $thread->refresh(), $post, $isNewThread);
        }
    }

    /**
     * Einen Beitrag ändern (FO-08): die alte Fassung kommt in den Verlauf, mit Grund; beim Startbeitrag auch Titel,
     * Präfix und Themenfelder. Neue Anhänge werden gebunden.
     *
     * @param  array<string, mixed>  $body
     * @param  array{title?: string, prefix_id?: int|null, values?: array<string, string>}  $thread
     * @param  list<int>  $attachments
     */
    public function edit(Site $site, Customer $editor, Post $post, array $body, ?string $reason, array $thread = [], array $attachments = []): Post
    {
        DB::transaction(function () use ($site, $editor, $post, $body, $reason, $thread, $attachments): void {
            $model = Thread::query()->withoutGlobalScope(SiteScope::class)->findOrFail($post->thread_id);
            $isFirst = $model->first_post_id === $post->id;
            Revision::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'post_id' => $post->id, 'customer_id' => $editor->id, 'body' => $post->body,
                'title' => $isFirst ? $model->title : null, 'reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 200)]);
            $post->forceFill(['body' => $body, 'edited_at' => now(), 'edit_count' => $post->edit_count + 1, 'edit_reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 200),
                'edited_by' => $editor->id])->save();
            if ($isFirst && isset($thread['title'])) {
                $title = trim((string) preg_replace('/\s+/u', ' ', $thread['title']));
                $model->forceFill(['title' => $title, 'slug' => self::slug($title)] + (array_key_exists('prefix_id', $thread) ? ['prefix_id' => $thread['prefix_id']] : [])
                    + (array_key_exists('values', $thread) ? ['form_values' => $thread['values'] === [] ? null : $thread['values']] : []))->save();
            }
            app(Attachments::class)->assign($editor, $attachments, Forum::ATTACHMENTS, $post->id);
        });
        $this->after($site);
        app(ForumSearch::class)->thread($post->thread_id);

        return $post;
    }

    public static function slug(string $title): string
    {
        return Str::limit(Str::slug($title, '-', 'de') ?: 'thema', 100, '');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<int>  $attachments
     */
    private function createPost(Site $site, Customer $customer, Thread $thread, array $body, ?string $ipHash, array $attachments = [], bool $official = false, bool $pending = false): Post
    {
        $post = new Post(['site_id' => $site->id, 'thread_id' => $thread->id, 'customer_id' => $customer->id, 'body' => $body, 'ip_hash' => $ipHash]);
        $post->is_official = $official;
        $post->is_disabled = $pending;
        $post->save();
        app(Attachments::class)->assign($customer, $attachments, Forum::ATTACHMENTS, $post->id);
        $profile = $this->profiles->of($customer);
        if ($profile instanceof CommunityProfile && ! $pending) {
            CommunityProfile::query()->withoutGlobalScopes()->whereKey($profile->id)->increment('posts', 1, ['last_activity_at' => now()]);
        }

        return $post;
    }

    /** Zähler des Forums und aller Vorfahren, letzter Beitrag überall. */
    private function bumpNode(Node $node, Post $post, int $threads = 0): void
    {
        $ids = array_map(static fn (array $s): int => $s[1], $this->forum->chain($node->site, $node));
        Node::query()->withoutGlobalScopes()->whereIn('id', $ids)->update([
            'posts_count' => DB::raw('posts_count + 1'), 'threads_count' => $threads > 0 ? DB::raw('threads_count + 1') : DB::raw('threads_count'), 'last_post_id' => $post->id, 'updated_at' => now(),
        ]);
    }

    private function after(Site $site): void
    {
        $this->forum->forget();
        app(PageCache::class)->invalidate($site->id);
    }
}
