<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Http\Controllers;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Forms\SpamGuard;
use Basalto\Core\Models\Entry;
use Basalto\Core\Security\StopForumSpam;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Modules\Comments\CommentStatus;
use Basalto\Modules\Comments\Embedding;
use Basalto\Modules\Comments\Frontend\CommentsRenderer;
use Basalto\Modules\Comments\Jobs\NotifyAboutComment;
use Basalto\Modules\Comments\Models\Comment;
use Basalto\Modules\Comments\SpamCounter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Versand eines Kommentars aus dem Frontend (M-27): wie ein Formular des Kerns ohne CSRF-Token (die Seite kommt aus dem
 * Seiten-Cache), geschützt durch Honeypot, signiertes Zeit-Token und Limit je IP. Spam — auch eine verfälschte
 * Einbindung — wird still verworfen und gezählt; wer ihn schickt, sieht dasselbe Danke wie ein Mensch. Gespeichert wird
 * mit IP als Prüfsumme und Zeitpunkt der Zustimmung; je nach Einbindung wartet der Kommentar auf Freigabe oder steht
 * sofort, und die Redaktion bekommt eine Mail als Auftrag. Modul aus oder fremder Inhalt: 404.
 */
final class CommentSubmissionController
{
    public function __construct(private readonly CurrentSite $current, private readonly Backend $backend, private readonly SpamGuard $guard, private readonly SpamCounter $spam) {}

    public function __invoke(Request $request, int $entry): RedirectResponse
    {
        $site = $this->current->get();
        abort_if($site === null || ! $this->backend->moduleEnabled('comments', $site), 404);
        $target = Entry::query()->where('site_id', $site->id)->find($entry);
        abort_if($target === null, 404);
        $page = self::pagePath((string) $request->input('_seite', '/'));
        $lang = in_array($request->input('_sprache'), $site->localeCodes(), true) ? (string) $request->input('_sprache') : $site->default_locale;
        $thanks = $page.'?kommentar='.CommentsRenderer::SENT.'#kommentar-danke';
        $embedding = Embedding::fromSigned($target->id, (string) $request->input('_einbindung', ''));

        if ($embedding === null || $this->guard->reason($request, CommentsRenderer::guardHandle($target->id), $site->id, 'kommentar') !== null) {
            // Still: gespeichert wird nichts, nur gezählt — die Antwort ist die eines Menschen
            $this->spam->count($site->id);

            return redirect($thanks);
        }

        $replyIds = $embedding->replies
            ? Comment::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('entry_id', $target->id)->whereNull('parent_id')->where('status', CommentStatus::Approved->value)
            : null;
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'comment' => ['required', 'string', 'min:2', 'max:5000'],
            '_consent' => ['accepted'],
            'parent_id' => ['nullable', 'integer', $replyIds === null ? 'prohibited' : Rule::in($replyIds->pluck('id')->all())],
        ], ['_consent.accepted' => (string) __('comments::frontend.consent_required', [], $lang)], [
            'name' => (string) __('comments::frontend.name', [], $lang), 'email' => (string) __('comments::frontend.email', [], $lang),
            'website' => (string) __('comments::frontend.website', [], $lang), 'comment' => (string) __('comments::frontend.body', [], $lang),
        ]);
        if ($validator->fails()) {
            return redirect($page.'?kommentar='.CommentsRenderer::FAILED.'#kommentar-schreiben')->withErrors($validator, CommentsRenderer::BAG)
                ->withInput($request->only(['name', 'email', 'website', 'comment', '_consent', 'parent_id']));
        }

        // TB 50: Stop Forum Spam (wenn eingeschaltet) — ein Treffer landet als Spam in der Liste, damit ein Irrtum sichtbar bleibt
        $listed = app(StopForumSpam::class)->listed($site, (string) $request->input('email'), $request->ip()) !== null;
        $comment = Comment::query()->create([
            'site_id' => $site->id, 'entry_id' => $target->id, 'parent_id' => $request->filled('parent_id') ? (int) $request->input('parent_id') : null,
            'locale' => $lang, 'author_name' => trim((string) $request->input('name')), 'author_email' => mb_strtolower(trim((string) $request->input('email'))),
            'author_url' => $request->filled('website') ? trim((string) $request->input('website')) : null, 'body' => trim((string) $request->input('comment')),
            'status' => $listed ? CommentStatus::Spam : ($embedding->moderated ? CommentStatus::Pending : CommentStatus::Approved), 'ip_hash' => Comment::ipHash($request->ip()), 'consent_at' => now(),
        ]);
        if ($comment->status === CommentStatus::Approved) {
            app(PageCache::class)->invalidate($site->id);
        }
        Cache::forget('basalto.menu-counts.'.$site->id);
        if ($embedding->notify !== Embedding::NOTIFY_OFF && ! $listed) {
            NotifyAboutComment::dispatch($comment->id, $embedding->notify);
        }
        if ($comment->status === CommentStatus::Pending) {
            $request->session()->flash('comments.pending', $comment->id);
        }

        return redirect($thanks);
    }

    /** Nur ein Pfad dieser Site — sonst wäre `_seite` eine offene Weiterleitung. */
    private static function pagePath(string $value): string
    {
        $path = (string) parse_url($value, PHP_URL_PATH);

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';
    }
}
