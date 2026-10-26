<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRichText;
use Basalto\Core\Community\Relations;
use Basalto\Core\Community\Subscriptions;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;

/**
 * Benachrichtigungen des Forums (CR-006 FO-11) an Kundenkonten: Antwort in einem abonnierten Thema, neues Thema in
 * einem abonnierten Forum, Erwähnung, Zitat, Reaktion, hilfreichste Antwort. Jede Person bekommt je Beitrag höchstens
 * eine Nachricht — die wichtigste (Erwähnung vor Zitat vor Abo). Nie an die Person selbst, nie an jemanden, der den
 * Inhalt nicht lesen darf, nie an jemanden, der das Thema oder Forum ignoriert oder die schreibende Person blockiert
 * hat (TB 40). Wer einer Person folgt, erfährt von ihren neuen Themen.
 */
final class Notifier
{
    public const REPLY = 'forum.reply';

    public const THREAD = 'forum.thread';

    public const MENTION = 'forum.mention';

    public const QUOTE = 'forum.quote';

    public const REACTION = 'forum.reaction';

    public const BEST = 'forum.best_answer';

    /** TB 40: neues Thema einer Person, der man folgt */
    public const FOLLOWED = 'forum.followed';

    public function __construct(private readonly Forum $forum, private readonly Subscriptions $subscriptions) {}

    public function posted(Site $site, Customer $author, Thread $thread, Post $post, bool $isNewThread): void
    {
        $node = Node::query()->withoutGlobalScopes()->find($thread->node_id);
        if ($node === null) {
            return;
        }
        $name = $this->name($author);
        /** @var array<int, array{0: string, 1: string}> $send Konto → Art und Titel */
        $send = [];
        // Erwähnungen
        $mentioned = CommunityRichText::mentions($post->body);
        if ($mentioned !== []) {
            foreach (CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereIn('id', $mentioned)->pluck('customer_id') as $customerId) {
                $send[(int) $customerId] ??= [self::MENTION, ':name hat Sie in «:thread» erwähnt'];
            }
        }
        // TB 52: Gruppen — nur, wenn die Person Gruppen erwähnen darf; höchstens 100 Mitglieder je Gruppe
        $groupIds = CommunityRichText::groupMentions($post->body);
        if ($groupIds !== [] && app(VisitorAccess::class)->allows($site, $author, VisitorGroups::MENTION_PERMISSION)) {
            $groups = app(VisitorGroups::class);
            foreach ($groups->mentionable($site)->whereIn('id', $groupIds) as $group) {
                foreach ($groups->members($site, $group) as $customerId) {
                    $send[$customerId] ??= [self::MENTION, ':name hat Sie in «:thread» erwähnt'];
                }
            }
        }
        // Zitate eigener Beiträge
        foreach ($this->quotedPosts($post->body) as $quoted) {
            $owner = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereKey($quoted)->value('customer_id');
            if ($owner !== null) {
                $send[(int) $owner] ??= [self::QUOTE, ':name hat Ihren Beitrag in «:thread» zitiert'];
            }
        }
        // Abos: das Thema, bei einem neuen Thema das Forum und seine Vorfahren
        foreach ($this->subscriptions->watchers('forum_thread', $thread->id) as $watcher) {
            $send[$watcher->id] ??= [self::REPLY, ':name hat in «:thread» geantwortet'];
        }
        if ($isNewThread) {
            foreach ($this->forum->chain($site, $node) as [, $nodeId]) {
                foreach ($this->subscriptions->watchers('forum_node', $nodeId) as $watcher) {
                    $send[$watcher->id] ??= [self::THREAD, ':name hat das Thema «:thread» eröffnet'];
                }
            }
        }
        // TB 40 (FO-18): wer der Person folgt, erfährt von ihrem neuen Thema (nach allem anderen)
        if ($isNewThread) {
            foreach (app(Relations::class)->followers($author->id) as $followerId) {
                $send[$followerId] ??= [self::FOLLOWED, ':name, dem Sie folgen, hat das Thema «:thread» eröffnet'];
            }
        }
        unset($send[$author->id]);
        // Wer die Person blockiert hat, hört nichts von ihr
        $send = array_filter($send, fn (array $v, int $id): bool => ! app(Relations::class)->blocks($id, $author->id), ARRAY_FILTER_USE_BOTH);
        if ($send === []) {
            return;
        }
        $customers = Customer::query()->withoutGlobalScopes()->whereIn('id', array_keys($send))->get()->keyBy('id');
        foreach ($send as $customerId => [$kind, $title]) {
            $customer = $customers->get($customerId);
            if ($customer === null || ! $this->mayReceive($site, $customer, $thread, $node)) {
                continue;
            }
            $notify = Notify::make($kind)->site($site)->title($title, ['name' => $name, 'thread' => $thread->title])
                ->url($this->postUrl($site, $customer, $post), 'Beitrag öffnen')->to($customer);
            if ($kind === self::REPLY) {
                $notify->titleMany(':count neue Antworten in «:thread»')->collapse('thread:'.$thread->id);
            }
            $notify->send();
        }
    }

    public function reacted(Site $site, Customer $by, Post $post, string $reaction): void
    {
        $owner = $post->customer_id === null ? null : Customer::query()->withoutGlobalScopes()->find($post->customer_id);
        $thread = Thread::query()->withoutGlobalScope(SiteScope::class)->find($post->thread_id);
        $node = $thread === null ? null : Node::query()->withoutGlobalScopes()->find($thread->node_id);
        if ($owner === null || $thread === null || $node === null || $owner->id === $by->id || ! $this->mayReceive($site, $owner, $thread, $node) || app(Relations::class)->blocks($owner->id, $by->id)) {
            return;
        }
        Notify::make(self::REACTION)->site($site)->title(':name hat auf Ihren Beitrag in «:thread» reagiert', ['name' => $this->name($by), 'thread' => $thread->title])
            ->titleMany(':count Reaktionen auf Ihren Beitrag in «:thread»')->collapse('post:'.$post->id)
            ->url($this->postUrl($site, $owner, $post), 'Beitrag öffnen')->to($owner)->send();
    }

    public function bestAnswer(Site $site, Customer $by, Thread $thread, Post $post): void
    {
        $owner = $post->customer_id === null ? null : Customer::query()->withoutGlobalScopes()->find($post->customer_id);
        if ($owner === null || $owner->id === $by->id) {
            return;
        }
        Notify::make(self::BEST)->site($site)->title('Ihre Antwort in «:thread» ist die hilfreichste', ['thread' => $thread->title])
            ->url($this->postUrl($site, $owner, $post), 'Beitrag öffnen')->to($owner)->send();
    }

    private function mayReceive(Site $site, Customer $customer, Thread $thread, Node $node): bool
    {
        if (! $this->forum->can($site, $customer, Forum::READ, $node)) {
            return false;
        }
        if (in_array($thread->id, $this->subscriptions->ignored($customer, 'forum_thread'), true)) {
            return false;
        }
        $ignoredNodes = $this->subscriptions->ignored($customer, 'forum_node');

        return array_intersect($ignoredNodes, array_map(static fn (array $s): int => $s[1], $this->forum->chain($site, $node))) === [];
    }

    private function postUrl(Site $site, Customer $customer, Post $post): ?string
    {
        $locale = in_array($customer->locale, $site->localeCodes(), true) ? $customer->locale : $site->default_locale;
        $base = $this->forum->basePath($site, $locale);

        return $base === null ? null : $base.'/beitrag/'.$post->id;
    }

    private function name(Customer $customer): string
    {
        return CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->value('name') ?? (string) __('Mitglied');
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<int>
     */
    private function quotedPosts(array $doc): array
    {
        $ids = [];
        $walk = static function (array $node) use (&$walk, &$ids): void {
            if (($node['type'] ?? null) === 'blockquote' && is_numeric($node['attrs']['post'] ?? null)) {
                $ids[] = (int) $node['attrs']['post'];
            }
            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($doc);

        return array_values(array_unique($ids));
    }
}
