<?php

declare(strict_types=1);

namespace Basalto\Modules\Comments\Frontend;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Content\Pages;
use Basalto\Core\Forms\SpamGuard;
use Basalto\Core\Models\Site;
use Basalto\Core\Security\ProofOfWork;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Support\Text;
use Basalto\Lava\Runtime\Context;
use Basalto\Modules\Comments\CommentStatus;
use Basalto\Modules\Comments\Embedding;
use Basalto\Modules\Comments\Models\Comment;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag as Messages;
use Illuminate\Support\ViewErrorBag;

/**
 * Der Tag `{% comments.list %}` (M-27): freigegebene Kommentare eines Inhalts mit Antworten (eine Ebene), blätterbar,
 * darunter das Formular — barrierearm wie die Formulare des Kerns (Beschriftung je Feld, Fehler am Feld mit
 * `aria-describedby`, Zusammenfassung als `role="alert"`, Danke als `role="status"`), ohne Skript und ohne
 * Fremdressource (Initialen statt Bild). Danke, Fehler, Antwort und weitere Seiten kommen über eine Adresse mit
 * Abfrage — die geht nie in den Seiten-Cache. Ist das Modul für die Site aus, gibt der Tag nichts aus.
 */
final class CommentsRenderer
{
    public const SENT = 'danke';

    public const FAILED = 'fehler';

    public const PAGE = 'kommentarseite';

    public const REPLY = 'antwort';

    public const BAG = 'comments';

    public function __construct(private readonly CurrentSite $current, private readonly Backend $backend, private readonly Pages $pages, private readonly SpamGuard $guard, private readonly ProofOfWork $pow) {}

    /** Der Name, unter dem das Zeit-Token des Spam-Schutzes für einen Inhalt signiert wird. */
    public static function guardHandle(int $entryId): string
    {
        return 'comments-'.$entryId;
    }

    /** @param  array<string, mixed>  $named */
    public function render(array $named, Context $ctx, ?Request $request = null): string
    {
        $site = $this->current->get();
        if ($site === null || ! $this->backend->moduleEnabled('comments', $site)) {
            return '';
        }
        $page = is_array($ctx->get('page')) ? $ctx->get('page') : [];
        $siteVars = is_array($ctx->get('site')) ? $ctx->get('site') : [];
        $lang = is_string($page['locale'] ?? null) ? $page['locale'] : (is_string($siteVars['language'] ?? null) ? $siteVars['language'] : $site->default_locale);
        $path = is_string($page['path'] ?? null) ? $page['path'] : null;
        $entryId = $path === null ? null : ($this->pages->entryIdAt($site, $lang, $path) ?? $this->pages->entryIdAt($site, $lang, $path, false));
        if ($entryId === null) {
            return '';
        }
        $request ??= request();
        $embedding = Embedding::fromNamed($named);
        $url = is_string($page['url'] ?? null) ? (string) parse_url($page['url'], PHP_URL_PATH) : '';
        $pagePath = $url !== '' ? $url : $path;

        $base = Comment::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('entry_id', $entryId)->where('status', CommentStatus::Approved->value);
        $total = (clone $base)->count();
        $topCount = (clone $base)->whereNull('parent_id')->count();
        $last = max(1, (int) ceil($topCount / $embedding->perPage));
        $current = min($last, max(1, (int) $request->query(self::PAGE, '1')));
        $top = (clone $base)->whereNull('parent_id')
            ->orderBy('created_at', $embedding->newestFirst ? 'desc' : 'asc')->orderBy('id', $embedding->newestFirst ? 'desc' : 'asc')
            ->forPage($current, $embedding->perPage)->get();
        $replies = $top->isEmpty() ? new Collection : (clone $base)->whereIn('parent_id', $top->pluck('id'))->orderBy('created_at')->orderBy('id')->get()->groupBy('parent_id');
        $pending = $this->ownPending($request, $site, $entryId);
        $replyTo = $this->replyTarget($request, $site, $entryId, $embedding);

        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $html = sprintf('<section class="comments" id="kommentare" aria-labelledby="kommentare-titel"><h2 id="kommentare-titel">%s</h2>',
            $e($total > 0 ? trans_choice('comments::frontend.count', $total, ['count' => $total], $lang) : __('comments::frontend.heading', [], $lang)));
        if ($top->isEmpty() && $pending === null) {
            $html .= sprintf('<p class="comments-empty">%s</p>', $e(__('comments::frontend.empty', [], $lang)));
        } else {
            $html .= '<ol class="comment-list">';
            foreach ($top as $comment) {
                $children = $replies->get($comment->id, new Collection);
                $own = $pending !== null && $pending->parent_id === $comment->id ? $pending : null;
                $html .= $this->item($comment, $site, $lang, $pagePath, $embedding, array_values($children->all()), $own);
            }
            if ($pending !== null && ($pending->parent_id === null || ! $top->contains('id', $pending->parent_id))) {
                $html .= $this->item($pending, $site, $lang, $pagePath, $embedding, [], null);
            }
            $html .= '</ol>';
        }
        if ($last > 1) {
            $html .= $this->pagination($pagePath, $current, $last, $lang, $embedding);
        }
        if ($request->query('kommentar') === self::SENT) {
            $html .= sprintf('<p class="comments-thanks" id="kommentar-danke" role="status" tabindex="-1">%s</p>',
                $e(__($embedding->moderated ? 'comments::frontend.thanks_moderated' : 'comments::frontend.thanks_published', [], $lang)));
        }
        $html .= $embedding->closed
            ? sprintf('<p class="comments-closed">%s</p>', $e(__('comments::frontend.closed', [], $lang)))
            : $this->form($request, $entryId, $lang, $pagePath, $embedding, $replyTo);

        return $html.'</section>';
    }

    /**
     * Ein Kommentar mit seinen Antworten — die eigene, wartende Antwort hängt dort, wo sie erscheinen wird.
     *
     * @param  list<Comment>  $replies
     */
    private function item(Comment $comment, Site $site, string $lang, string $pagePath, Embedding $embedding, array $replies, ?Comment $ownReply): string
    {
        $html = '<li class="'.$this->classes($comment).'" id="kommentar-'.$comment->id.'">'.$this->article($comment, $site, $lang);
        if ($comment->status === CommentStatus::Approved && $comment->parent_id === null && $embedding->replies && ! $embedding->closed) {
            // Der sichtbare Text steht am Anfang des zugänglichen Namens (WCAG 2.5.3), der Name sagt, worauf
            $reply = (string) __('comments::frontend.reply', [], $lang);
            $html .= sprintf('<p class="comment-actions"><a class="comment-reply-link" href="%s" aria-label="%s">%s</a></p>',
                htmlspecialchars($pagePath.'?'.self::REPLY.'='.$comment->id.'#kommentar-schreiben', ENT_QUOTES), htmlspecialchars($reply.': '.$comment->author_name, ENT_QUOTES),
                htmlspecialchars($reply, ENT_QUOTES));
        }
        if ($ownReply !== null) {
            $replies[] = $ownReply;
        }
        if ($replies !== []) {
            $html .= '<ol class="comment-replies">';
            foreach ($replies as $reply) {
                $html .= '<li class="'.$this->classes($reply).'" id="kommentar-'.$reply->id.'">'.$this->article($reply, $site, $lang).'</li>';
            }
            $html .= '</ol>';
        }

        return $html.'</li>';
    }

    private function classes(Comment $comment): string
    {
        return trim('comment'.($comment->user_id !== null ? ' comment-staff' : '').($comment->status === CommentStatus::Pending ? ' comment-pending' : ''));
    }

    private function article(Comment $comment, Site $site, string $lang): string
    {
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $created = $comment->created_at?->copy()->timezone($site->timezone !== '' ? $site->timezone : (string) config('app.timezone'));
        $name = $comment->author_url !== null && $comment->author_url !== '' && $comment->user_id === null
            ? sprintf('<a href="%s" rel="nofollow ugc noopener">%s</a>', $e($comment->author_url), $e($comment->author_name))
            : $e($comment->author_name);
        $badge = $comment->user_id !== null ? sprintf(' <span class="comment-badge">%s</span>', $e(__('comments::frontend.staff', [], $lang))) : '';
        $time = $created === null ? '' : sprintf(' <time datetime="%s">%s</time>', $e($created->toIso8601String()), $e($created->format($lang === 'en' ? 'j M Y, H:i' : 'd.m.Y, H:i')));
        $note = $comment->status === CommentStatus::Pending ? sprintf('<p class="comment-note">%s</p>', $e(__('comments::frontend.pending', [], $lang))) : '';

        return sprintf('<article class="comment-inner"><span class="comment-avatar" aria-hidden="true">%s</span><div class="comment-main"><p class="comment-meta"><b class="comment-author">%s</b>%s%s</p><div class="comment-body">%s</div>%s</div></article>',
            $e(self::initials($comment->author_name)), $name, $badge, $time, self::body($comment->body), $note);
    }

    private function pagination(string $pagePath, int $current, int $last, string $lang, Embedding $embedding): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
        $link = static fn (int $page): string => $e($pagePath.($page > 1 ? '?'.self::PAGE.'='.$page : '').'#kommentare');
        // Weiter heisst bei «älteste zuerst» neuere Kommentare, bei «neueste zuerst» ältere
        $back = $embedding->newestFirst ? 'comments::frontend.newer' : 'comments::frontend.older';
        $forward = $embedding->newestFirst ? 'comments::frontend.older' : 'comments::frontend.newer';
        $html = sprintf('<nav class="comments-pages" aria-label="%s"><ul>', $e(__('comments::frontend.pages', [], $lang)));
        if ($current > 1) {
            $html .= sprintf('<li><a href="%s" rel="prev">%s</a></li>', $link($current - 1), $e(__($back, [], $lang)));
        }
        $html .= sprintf('<li aria-current="page">%s</li>', $e(__('comments::frontend.page_of', ['current' => $current, 'last' => $last], $lang)));
        if ($current < $last) {
            $html .= sprintf('<li><a href="%s" rel="next">%s</a></li>', $link($current + 1), $e(__($forward, [], $lang)));
        }

        return $html.'</ul></nav>';
    }

    private function form(Request $request, int $entryId, string $lang, string $pagePath, Embedding $embedding, ?Comment $replyTo): string
    {
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $t = static fn (string $key): string => htmlspecialchars((string) __('comments::frontend.'.$key, [], $lang), ENT_QUOTES);
        $failed = $request->query('kommentar') === self::FAILED;
        $errors = $failed ? $this->errors($request) : new Messages;
        $old = $failed && $request->hasSession() ? (array) $request->session()->getOldInput() : [];
        // Pflicht sagt das Attribut «required» dem Screenreader; der Stern ist nur fürs Auge
        $required = ' <span class="form-required" aria-hidden="true">*</span>';

        $html = sprintf('<form class="form comment-form" id="kommentar-schreiben" method="post" action="/comments/%d" aria-labelledby="kommentar-schreiben-titel">', $entryId);
        $html .= sprintf('<h3 id="kommentar-schreiben-titel">%s</h3>', $replyTo !== null ? $e(__('comments::frontend.reply_to', ['name' => $replyTo->author_name], $lang)) : $t('write'));
        if ($replyTo !== null) {
            $html .= sprintf('<p class="comment-reply-cancel"><a href="%s">%s</a></p><input type="hidden" name="parent_id" value="%d">', $e($pagePath.'#kommentar-schreiben'), $t('reply_cancel'), $replyTo->id);
        }
        if ($errors->isNotEmpty()) {
            $html .= sprintf('<div class="form-errors" role="alert" tabindex="-1"><p>%s</p><ul>%s</ul></div>', $t('errors'),
                implode('', array_map(static fn (string $m): string => '<li>'.htmlspecialchars($m, ENT_QUOTES).'</li>', $errors->all())));
        }
        $html .= sprintf('<input type="hidden" name="%s" value="%s"><input type="hidden" name="_seite" value="%s"><input type="hidden" name="_sprache" value="%s"><input type="hidden" name="_einbindung" value="%s">',
            SpamGuard::TOKEN, $e($this->guard->token(self::guardHandle($entryId))), $e($pagePath), $e($lang), $e($embedding->sign($entryId)));
        $html .= sprintf('<div class="form-hp" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden"><label>Website<input type="text" name="%s" tabindex="-1" autocomplete="off"></label></div>', SpamGuard::HONEYPOT);

        $field = function (string $name, string $label, string $control, ?string $help, MessageBag $errors): string {
            $id = 'kommentar-'.$name;
            $error = $errors->first($name);
            $describedBy = array_filter([$error !== '' ? $id.'-fehler' : null, $help !== null ? $id.'-hilfe' : null]);
            $aria = ($describedBy !== [] ? ' aria-describedby="'.implode(' ', $describedBy).'"' : '').($error !== '' ? ' aria-invalid="true"' : '');

            return sprintf('<div class="form-field comment-field%s"><label for="%s">%s</label>%s%s%s</div>', $error !== '' ? ' has-error' : '', $id, $label,
                str_replace('{aria}', $aria, $control), $error !== '' ? sprintf('<p class="form-error" id="%s-fehler">%s</p>', $id, htmlspecialchars($error, ENT_QUOTES)) : '',
                $help !== null ? sprintf('<p class="form-help" id="%s-hilfe">%s</p>', $id, htmlspecialchars($help, ENT_QUOTES)) : '');
        };
        $value = static fn (string $name): string => htmlspecialchars(is_scalar($old[$name] ?? null) ? (string) $old[$name] : '', ENT_QUOTES);
        $html .= '<div class="comment-fields">';
        $html .= $field('name', $t('name').$required, sprintf('<input type="text" id="kommentar-name" name="name" value="%s" required maxlength="120" autocomplete="name"{aria}>', $value('name')), null, $errors);
        $html .= $field('email', $t('email').$required, sprintf('<input type="email" id="kommentar-email" name="email" value="%s" required maxlength="254" autocomplete="email"{aria}>', $value('email')),
            (string) __('comments::frontend.email_help', [], $lang), $errors);
        $html .= '</div>';
        $html .= $field('website', $t('website'), sprintf('<input type="url" id="kommentar-website" name="website" value="%s" maxlength="500" autocomplete="url"{aria}>', $value('website')), null, $errors);
        $html .= $field('comment', $t('body').$required, sprintf('<textarea id="kommentar-comment" name="comment" rows="6" required maxlength="5000"{aria}>%s</textarea>', $value('comment')), null, $errors);
        $consentError = $errors->first('_consent');
        $html .= sprintf('<div class="form-field form-consent%s"><label><input type="checkbox" id="kommentar-_consent" name="_consent" value="1" required%s%s> %s</label>%s</div>',
            $consentError !== '' ? ' has-error' : '', ($old['_consent'] ?? null) ? ' checked' : '',
            $consentError !== '' ? ' aria-invalid="true" aria-describedby="kommentar-_consent-fehler"' : '', $t('consent'),
            $consentError !== '' ? sprintf('<p class="form-error" id="kommentar-_consent-fehler">%s</p>', htmlspecialchars($consentError, ENT_QUOTES)) : '');
        // TB 50: Spamschutz im Browser, wenn er für die Site an ist
        if ($this->pow->enabled($this->current->get(), 'kommentar')) {
            $html .= $this->pow->field('kommentar', $lang);
        }
        $html .= sprintf('<button type="submit">%s</button><p class="comment-hint">%s</p>', $t('submit'), $t($embedding->moderated ? 'note_moderated' : 'note_published'));

        return $html.'</form>';
    }

    /** Der eigene Kommentar nach dem Senden, solange er wartet — nur in der Sitzung, die ihn geschickt hat. */
    private function ownPending(Request $request, Site $site, int $entryId): ?Comment
    {
        $id = $request->query('kommentar') === self::SENT && $request->hasSession() ? $request->session()->get('comments.pending') : null;
        if (! is_int($id)) {
            return null;
        }

        return Comment::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('entry_id', $entryId)->where('status', CommentStatus::Pending->value)->find($id);
    }

    /** Die Antwort zielt auf einen freigegebenen Kommentar der ersten Ebene dieses Inhalts — sonst gibt es keine. */
    private function replyTarget(Request $request, Site $site, int $entryId, Embedding $embedding): ?Comment
    {
        if (! $embedding->replies || $embedding->closed) {
            return null;
        }
        $id = $request->query(self::REPLY);
        if (! is_numeric($id) && $request->query('kommentar') === self::FAILED && $request->hasSession()) {
            $id = $request->session()->getOldInput('parent_id');
        }
        if (! is_numeric($id)) {
            return null;
        }

        return Comment::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('entry_id', $entryId)->whereNull('parent_id')
            ->where('status', CommentStatus::Approved->value)->find((int) $id);
    }

    private function errors(Request $request): MessageBag
    {
        $bags = $request->hasSession() ? $request->session()->get('errors') : null;

        return $bags instanceof ViewErrorBag ? $bags->getBag(self::BAG) : new Messages;
    }

    /** Zwei Anfangsbuchstaben statt eines Bilds — kein Gravatar, keine Fremdressource. */
    public static function initials(string $name): string
    {
        return Text::initials($name);
    }

    /** Text als Absätze — maskiert, Leerzeilen trennen Absätze, einfache Umbrüche bleiben. */
    public static function body(string $text): string
    {
        $paragraphs = preg_split('/\R{2,}/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(static fn (string $p): string => '<p>'.nl2br(htmlspecialchars(trim($p), ENT_QUOTES), false).'</p>', $paragraphs));
    }
}
