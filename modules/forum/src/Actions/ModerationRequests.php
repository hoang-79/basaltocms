<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Actions;

use Basalto\Core\Community\CommunityReport;
use Basalto\Core\Community\ConversationMessage;
use Basalto\Core\Community\Conversations;
use Basalto\Core\Community\ModerationLog;
use Basalto\Core\Community\Moderator;
use Basalto\Core\Community\Reports;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\NodeType;
use Closure;
use Illuminate\Http\Request;

/**
 * Formulare der Moderation lesen (CR-006 FO-15) — dieselben Felder und Aktionen im Frontend (Leiste «n ausgewählt»,
 * Menü am Thema) und im Extranet. Wer was darf, entscheidet der Aufrufer mit `$may` je Forum; hier wird nur gelesen,
 * gefiltert und an {@see Moderation} weitergegeben. Die Antwort ist ein Satz für die Rückmeldung.
 */
final class ModerationRequests
{
    /** Aktionen für Themen — Schlüssel im Formular → Bezeichnung */
    public const THREAD_ACTIONS = [
        'schliessen' => 'Schliessen', 'oeffnen' => 'Öffnen', 'anheften' => 'Anheften', 'loesen' => 'Lösen',
        'ankuendigen' => 'Als Ankündigung', 'ankuendigung_aus' => 'Keine Ankündigung mehr', 'empfehlen' => 'Empfehlen', 'nicht_empfehlen' => 'Nicht mehr empfehlen', 'verschieben' => 'Verschieben', 'kopieren' => 'Kopieren', 'zusammenfuehren' => 'Zusammenführen',
        'freischalten' => 'Freischalten', 'papierkorb' => 'In den Papierkorb', 'wiederherstellen' => 'Wiederherstellen', 'loeschen' => 'Endgültig löschen',
    ];

    /** Aktionen für Beiträge */
    public const POST_ACTIONS = [
        'freischalten' => 'Freischalten', 'ausblenden' => 'Ausblenden', 'sperren' => 'Bearbeiten sperren', 'entsperren' => 'Bearbeiten erlauben', 'teilen' => 'In ein neues Thema teilen',
        'papierkorb' => 'In den Papierkorb', 'wiederherstellen' => 'Wiederherstellen', 'loeschen' => 'Endgültig löschen',
    ];

    public function __construct(private readonly Moderation $moderation) {}

    /**
     * @param  Closure(Node): bool  $may
     * @return array{ok: bool, message: string, thread?: Thread|null}
     */
    public function threads(Site $site, Moderator $by, Request $request, Closure $may, string $lang): array
    {
        $action = (string) $request->input('aktion');
        $threads = $this->loadThreads($site, $request->input('themen'));
        $nodes = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('id');
        $threads = array_values(array_filter($threads, static fn (Thread $t): bool => ($node = $nodes->get($t->node_id)) !== null && $may($node)));
        if (! array_key_exists($action, self::THREAD_ACTIONS) || $threads === []) {
            return ['ok' => false, 'message' => __('Bitte ein Thema und eine Aktion wählen.', [], $lang)];
        }
        $reason = is_string($request->input('grund')) ? $request->input('grund') : null;
        if ($action === 'verschieben') {
            $target = $nodes->get((int) $request->input('ziel'));
            if ($target === null || $target->type !== NodeType::Board || ! $may($target)) {
                return ['ok' => false, 'message' => __('Bitte ein Forum wählen, in das die Themen ziehen.', [], $lang)];
            }
            foreach ($threads as $thread) {
                $this->moderation->move($site, $by, $thread, $target, $request->boolean('verweis'));
            }

            return ['ok' => true, 'message' => trans_choice(':count Thema verschoben.|:count Themen verschoben.', count($threads), ['count' => count($threads)], $lang)];
        }
        // Kopieren (§ 3.2): das Original bleibt, die Kopie steht im Ziel — mit allen sichtbaren Beiträgen
        if ($action === 'kopieren') {
            $target = $nodes->get((int) $request->input('ziel'));
            if ($target === null || $target->type !== NodeType::Board || ! $may($target)) {
                return ['ok' => false, 'message' => __('Bitte ein Forum wählen, in das die Kopie kommt.', [], $lang)];
            }
            $copies = array_map(fn (Thread $thread): Thread => $this->moderation->copy($site, $by, $thread, $target), $threads);

            return ['ok' => true, 'message' => trans_choice(':count Thema kopiert.|:count Themen kopiert.', count($copies), ['count' => count($copies)], $lang), 'thread' => count($copies) === 1 ? $copies[0] : null];
        }
        if ($action === 'zusammenfuehren') {
            $into = $this->targetThread($site, $request->input('ziel_thema'));
            if ($into === null && count($threads) > 1) {
                usort($threads, static fn (Thread $a, Thread $b): int => $a->id <=> $b->id);
                $into = array_shift($threads);
            }
            if ($into === null || ($node = $nodes->get($into->node_id)) === null || ! $may($node) || $threads === []) {
                return ['ok' => false, 'message' => __('Zum Zusammenführen mindestens zwei Themen wählen oder die Nummer des Ziel-Themas angeben.', [], $lang)];
            }
            $this->moderation->merge($site, $by, $into, $threads);

            return ['ok' => true, 'message' => trans_choice(':count Thema in «:title» zusammengeführt.|:count Themen in «:title» zusammengeführt.', count($threads), ['count' => count($threads), 'title' => $into->title], $lang), 'thread' => $into];
        }
        foreach ($threads as $thread) {
            match ($action) {
                'schliessen' => $this->moderation->flag($site, $by, $thread, 'closed', true),
                'oeffnen' => $this->moderation->flag($site, $by, $thread, 'closed', false),
                'anheften' => $this->moderation->flag($site, $by, $thread, 'sticky', true),
                'loesen' => $this->moderation->flag($site, $by, $thread, 'sticky', false),
                'ankuendigen' => $this->moderation->flag($site, $by, $thread, 'announcement', true),
                'ankuendigung_aus' => $this->moderation->flag($site, $by, $thread, 'announcement', false),
                'empfehlen' => $this->moderation->flag($site, $by, $thread, 'featured', true),
                'nicht_empfehlen' => $this->moderation->flag($site, $by, $thread, 'featured', false),
                'freischalten' => $this->moderation->approveThread($site, $by, $thread),
                'papierkorb' => $this->moderation->trashThread($site, $by, $thread, $reason),
                'wiederherstellen' => $this->moderation->restoreThread($site, $by, $thread),
                default => $this->moderation->destroyThread($site, $by, $thread),
            };
        }

        return ['ok' => true, 'message' => trans_choice('«:action»: :count Thema.|«:action»: :count Themen.', count($threads), ['count' => count($threads), 'action' => __(self::THREAD_ACTIONS[$action], [], $lang)], $lang)];
    }

    /**
     * @param  Closure(Node): bool  $may
     * @return array{ok: bool, message: string, thread?: Thread|null}
     */
    public function posts(Site $site, Moderator $by, Request $request, Closure $may, string $lang): array
    {
        $action = (string) $request->input('aktion');
        $posts = $this->loadPosts($site, $request->input('beitraege'));
        $nodes = Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->get()->keyBy('id');
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', array_map(static fn (Post $p): int => $p->thread_id, $posts))->get()->keyBy('id');
        $posts = array_values(array_filter($posts, static fn (Post $p): bool => ($t = $threads->get($p->thread_id)) !== null && ($n = $nodes->get($t->node_id)) !== null && $may($n)));
        if (! array_key_exists($action, self::POST_ACTIONS) || $posts === []) {
            return ['ok' => false, 'message' => __('Bitte einen Beitrag und eine Aktion wählen.', [], $lang)];
        }
        $reason = is_string($request->input('grund')) ? $request->input('grund') : null;
        if ($action === 'teilen') {
            $from = $threads->get($posts[0]->thread_id);
            $title = is_string($request->input('titel')) ? $request->input('titel') : '';
            $target = $request->filled('ziel') ? $nodes->get((int) $request->input('ziel')) : null;
            if ($target !== null && ($target->type !== NodeType::Board || ! $may($target))) {
                $target = null;
            }
            $new = $from === null ? null : $this->moderation->split($site, $by, $from, $posts, $title, $target);
            if ($new === null) {
                return ['ok' => false, 'message' => __('Zum Teilen Beiträge ausser dem ersten wählen und einen Titel (mindestens 3 Zeichen) angeben.', [], $lang)];
            }

            return ['ok' => true, 'message' => __('Das neue Thema «:title» ist angelegt.', ['title' => $new->title], $lang), 'thread' => $new];
        }
        foreach ($posts as $post) {
            match ($action) {
                'freischalten' => $this->moderation->approvePost($site, $by, $post),
                'ausblenden' => $this->moderation->disablePost($site, $by, $post, reason: $reason),
                'sperren', 'entsperren' => $this->moderation->lockPostEditing($site, $by, $post, $action === 'sperren'),
                'papierkorb' => $this->moderation->trashPost($site, $by, $post, $reason),
                'wiederherstellen' => $this->moderation->restorePost($site, $by, $post),
                default => $this->moderation->destroyPost($site, $by, $post),
            };
        }

        return ['ok' => true, 'message' => trans_choice('«:action»: :count Beitrag.|«:action»: :count Beiträge.', count($posts), ['count' => count($posts), 'action' => __(self::POST_ACTIONS[$action], [], $lang)], $lang)];
    }

    /**
     * Eine Meldung entscheiden.
     *
     * @param  Closure(Node): bool  $may
     * @return array{ok: bool, message: string}
     */
    public function report(Site $site, Moderator $by, Request $request, Closure $may, string $lang): array
    {
        // TB 40: Nachrichten aus Konversationen entscheidet nur das Team (Extranet) — die Moderation sieht nur die Nachricht
        if ($request->input('art') === Conversations::REPORT_TYPE) {
            $message = ConversationMessage::query()->withoutGlobalScopes()->where('site_id', $site->id)->find((int) $request->input('id'));
            $decision = (string) $request->input('entscheid');
            if ($message === null || $by->user === null || ! in_array($decision, [CommunityReport::HIDDEN, CommunityReport::KEPT], true)) {
                return ['ok' => false, 'message' => __('Diese Meldung gibt es nicht mehr.', [], $lang)];
            }
            if ($decision === CommunityReport::HIDDEN) {
                app(Conversations::class)->removeMessage($message);
            }
            app(Reports::class)->resolve(Conversations::REPORT_TYPE, $message->id, $decision, $by);
            app(ModerationLog::class)->record($site, $decision === CommunityReport::HIDDEN ? 'report.hidden' : 'report.kept', $by, Conversations::REPORT_TYPE, $message->id, null, $message->customer_id);

            return ['ok' => true, 'message' => $decision === CommunityReport::HIDDEN ? __('Die Nachricht ist entfernt, die Meldungen sind erledigt.', [], $lang)
                : __('Die Meldungen sind zurückgewiesen, die Nachricht bleibt.', [], $lang)];
        }
        $post = $this->loadPosts($site, [$request->input('id')])[0] ?? null;
        $thread = $post === null ? null : Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->find($post->thread_id);
        $node = $thread === null ? null : Node::query()->withoutGlobalScopes()->find($thread->node_id);
        $decision = (string) $request->input('entscheid');
        if ($post === null || $node === null || ! $may($node) || ! in_array($decision, [CommunityReport::HIDDEN, CommunityReport::KEPT], true)) {
            return ['ok' => false, 'message' => __('Diese Meldung gibt es nicht mehr.', [], $lang)];
        }
        $this->moderation->decideReport($site, $by, $post, $decision);

        return ['ok' => true, 'message' => $decision === CommunityReport::HIDDEN ? __('Der Beitrag liegt im Papierkorb, die Meldungen sind erledigt.', [], $lang)
            : __('Die Meldungen sind zurückgewiesen, der Beitrag bleibt.', [], $lang)];
    }

    /** @return list<Thread> Themen der Site, auch wartende und im Papierkorb */
    private function loadThreads(Site $site, mixed $ids): array
    {
        return array_values(Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('site_id', $site->id)->whereIn('id', self::ids($ids))->get()->all());
    }

    /** @return list<Post> */
    private function loadPosts(Site $site, mixed $ids): array
    {
        return array_values(Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('site_id', $site->id)->whereIn('id', self::ids($ids))->get()->all());
    }

    /** @return list<int> höchstens hundert Kennungen aus dem Formular */
    private static function ids(mixed $ids): array
    {
        return array_slice(array_values(array_unique(array_map('intval', array_filter((array) $ids, 'is_numeric')))), 0, 100);
    }

    /** Das Ziel-Thema aus einer Nummer oder einer Adresse (`…/thema/12-titel`). */
    private function targetThread(Site $site, mixed $input): ?Thread
    {
        $text = is_string($input) || is_int($input) ? trim((string) $input) : '';
        if ($text === '' || preg_match('~(?:thema/)?(\d+)(?:-[a-z0-9-]*)?/?$~', $text, $m) !== 1) {
            return null;
        }

        return Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNull('moved_to_id')->find((int) $m[1]);
    }
}
