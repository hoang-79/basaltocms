<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Http\Controllers;

use Basalto\Core\Community\CommunityNotifications;
use Basalto\Core\Community\Subscriptions;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Actions\Posting;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Poll;
use Basalto\Modules\Forum\Models\PollOption;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\ReadTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Aktivität im Forum (CR-006 FO-10, FO-12): abonnieren und ignorieren, als gelesen markieren, hilfreichste Antwort,
 * abstimmen, Benachrichtigungen gelesen. Alles Formulare mit CSRF-Token, zurück auf die Seite, von der sie kamen.
 */
final class ActivityController
{
    public function __construct(private readonly Forum $forum, private readonly Subscriptions $subscriptions, private readonly ReadTracker $reads, private readonly Posting $posting) {}

    /** Thema oder Forum abonnieren, ignorieren oder zurücksetzen. */
    public function subscribe(Request $request): RedirectResponse
    {
        [$site, $customer, $back] = $this->context($request);
        $data = $request->validate(['art' => ['required', 'in:thema,forum'], 'id' => ['required', 'integer'], 'modus' => ['required', 'in:watch,ignore,none']]);
        if ($data['art'] === 'thema') {
            $thread = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->find((int) $data['id']);
            $node = $thread === null ? null : Node::query()->withoutGlobalScopes()->find($thread->node_id);
            abort_if($thread === null || $node === null || ! $this->forum->can($site, $customer, Forum::READ, $node), 404);
            $this->subscriptions->set($customer, 'forum_thread', $thread->id, $data['modus'] === 'none' ? null : (string) $data['modus']);
        } else {
            $node = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->find((int) $data['id']);
            abort_if($node === null || ! $this->forum->can($site, $customer, Forum::VIEW, $node), 404);
            $this->subscriptions->set($customer, 'forum_node', $node->id, $data['modus'] === 'none' ? null : (string) $data['modus']);
        }

        return redirect($back);
    }

    /** Ein Forum oder (ohne Forum) alles als gelesen markieren. */
    public function markRead(Request $request): RedirectResponse
    {
        [$site, $customer, $back] = $this->context($request);
        $nodeId = (int) $request->input('forum', 0);
        abort_if($nodeId > 0 && Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereKey($nodeId)->doesntExist(), 404);
        $this->reads->markNode($customer, $nodeId);

        return redirect($back);
    }

    /** Die hilfreichste Antwort wählen oder lösen. */
    public function best(Request $request, int $thread): RedirectResponse
    {
        [$site, $customer, $back] = $this->context($request);
        $model = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->find($thread);
        $node = $model === null ? null : Node::query()->withoutGlobalScopes()->find($model->node_id);
        abort_if($model === null || $node === null, 404);
        abort_unless($this->forum->canMarkBest($site, $customer, $model, $node), 403);
        $post = $request->filled('beitrag') ? Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $model->id)->where('is_disabled', false)->find((int) $request->input('beitrag')) : null;
        abort_if($request->filled('beitrag') && ($post === null || $post->id === $model->first_post_id), 422);
        $this->posting->markBest($site, $customer, $model, $post);

        return redirect($back.($post !== null ? '#beitrag-'.$post->id : ''));
    }

    /** Abstimmen — einmal je Konto, höchstens so viele Optionen wie erlaubt, nur solange die Umfrage läuft. */
    public function vote(Request $request, int $poll): RedirectResponse
    {
        [$site, $customer, $back] = $this->context($request);
        $model = Poll::query()->withoutGlobalScopes()->where('site_id', $site->id)->find($poll);
        $thread = $model === null ? null : Thread::query()->withoutGlobalScope(SiteScope::class)->find($model->thread_id);
        $node = $thread === null ? null : Node::query()->withoutGlobalScopes()->find($thread->node_id);
        abort_if($model === null || $thread === null || $node === null || ! $this->forum->can($site, $customer, Forum::REPLY, $node), 404);
        $lang = is_string($request->input('_sprache')) ? (string) $request->input('_sprache') : $site->default_locale;
        if (! $model->isOpen()) {
            return redirect($back)->withErrors(['umfrage' => __('Die Umfrage ist beendet.', [], $lang)], 'forum');
        }
        if (DB::table('forum_poll_votes')->where('poll_id', $model->id)->where('customer_id', $customer->id)->exists()) {
            return redirect($back)->withErrors(['umfrage' => __('Sie haben schon abgestimmt.', [], $lang)], 'forum');
        }
        $chosen = array_values(array_unique(array_map('intval', (array) $request->input('optionen', []))));
        $valid = PollOption::query()->where('poll_id', $model->id)->whereIn('id', $chosen)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($valid === [] || count($valid) !== count($chosen) || count($valid) > $model->max_choices) {
            return redirect($back)->withErrors(['umfrage' => trans_choice('Bitte höchstens :count Antwort wählen.|Bitte höchstens :count Antworten wählen.', $model->max_choices, ['count' => $model->max_choices], $lang)], 'forum');
        }
        DB::transaction(static function () use ($model, $valid, $customer): void {
            foreach ($valid as $optionId) {
                DB::table('forum_poll_votes')->insert(['poll_id' => $model->id, 'option_id' => $optionId, 'customer_id' => $customer->id, 'created_at' => now()]);
                PollOption::query()->whereKey($optionId)->increment('votes');
            }
            Poll::query()->withoutGlobalScopes()->whereKey($model->id)->increment('voters');
        });

        return redirect($back.'#umfrage');
    }

    public function notificationsRead(Request $request): RedirectResponse
    {
        [, $customer, $back] = $this->context($request);
        app(CommunityNotifications::class)->markAllRead($customer);

        return redirect($back);
    }

    /** @return array{0: Site, 1: Customer, 2: string} */
    private function context(Request $request): array
    {
        $site = app(CurrentSite::class)->get();
        $customer = CustomerAuth::current();
        abort_if($site === null || $customer === null, 403);

        return [$site, $customer, Html::pagePath($request->input('_seite'))];
    }
}
