<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityReport;
use Basalto\Core\Community\Http\ReportController;
use Basalto\Core\Community\ModerationSettings;
use Basalto\Core\Community\MountResult;
use Basalto\Core\Community\Notices;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Actions\ModerationRequests;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\NodeType;

/**
 * Moderation an Ort und Stelle im Forum (CR-006 FO-15–17), als Teil von {@see ForumMount}: Ankreuzfelder und die
 * Leiste «n ausgewählt» in Listen und Themen, das Menü am Thema, Freischalten, Meldungen entscheiden, Verwarnen und
 * «Als Spammer entfernen» am Beitrag, «Melden» für alle Angemeldeten, Hinweise und Rückmeldungen oben, die Liste
 * `/community/moderation`. Alle Formulare gehen an dieselben Aktionen wie im Extranet.
 */
trait ModeratesInPlace
{
    /** @var list<string> Bereiche der Seite für die Hinweise (`forum`, `forum:7`, …) */
    private array $areas = ['forum'];

    /** @var array<int, bool> */
    private array $moderates = [];

    private function moderates(Node $node): bool
    {
        return $this->moderates[$node->id] ??= $this->forum->canModerate($this->r->site, $this->customer, $node);
    }

    /** Die Bereiche eines Forums: es selbst und seine Vorfahren. */
    private function areasOf(Node $node): void
    {
        $this->areas = array_map(static fn (array $s): string => 'forum:'.$s[1], $this->forum->chain($this->r->site, $node));
    }

    /** Oben im Forum: Rückmeldung einer Aktion, die Hinweise des Bereichs. */
    private function topNotes(): string
    {
        $request = $this->r->request;
        $status = $request->hasSession() ? $request->session()->get(ReportController::STATUS) : null;

        return (is_string($status) && $status !== '' ? Html::status($status, 'forum-status') : '')
            .app(Notices::class)->render($this->r->site, $this->customer, $this->areas, $this->lang(), $this->r->url($this->r->segments === [] ? '' : implode('/', $this->r->segments)));
    }

    /** Im Navigator: der Schild «Moderation» mit der Zahl offener Fälle — nur für Moderierende. */
    private function moderationLink(): string
    {
        if (! $this->forum->moderatesSomewhere($this->r->site, $this->customer)) {
            return '';
        }
        $nodes = $this->forum->moderatedNodes($this->r->site, $this->customer);
        $open = $this->openCases($nodes);

        return $this->navButton('moderation', 'shield', $this->t('Moderation'), $open > 0 ? '<b class="forum-glocke__zahl">'.$open.'</b>' : '', ' forum-link-moderation');
    }

    /** @param  list<int>  $nodes */
    private function openCases(array $nodes): int
    {
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('site_id', $this->r->site->id)->whereIn('node_id', $nodes)->select('id');
        $reported = app(Reports::class)->pending($this->r->site->id, [Moderation::REPORT_TYPE], 500);
        $postIds = array_map(static fn (array $g): int => $g['id'], $reported);
        $reports = $postIds === [] ? 0 : Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', $postIds)->whereIn('thread_id', $threads)->count();

        return $reports + Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $this->r->site->id)->where('is_disabled', true)->whereIn('thread_id', $threads)->count();
    }

    /** Hinweis bei Schreibsperre oder Sperre. */
    private function sanctionNote(): string
    {
        $sanctions = app(Sanctions::class);
        if ($this->customer === null || ! $sanctions->isMuted($this->customer)) {
            return '';
        }
        $until = $sanctions->until($this->customer);
        $banned = $sanctions->isBanned($this->customer);
        $text = $until === null
            ? ($banned ? $this->t('Ihr Konto ist für die Community gesperrt.') : $this->t('Sie haben eine Schreibsperre.'))
            : ($banned ? $this->t('Ihr Konto ist für die Community gesperrt bis :date.', ['date' => Markup::date($until, $this->lang())])
                : $this->t('Sie haben eine Schreibsperre bis :date.', ['date' => Markup::date($until, $this->lang())]));

        return sprintf('<p class="forum-note forum-note--sperre">%s</p>', $this->e($text));
    }

    /** Verborgene Felder jedes Moderationsformulars. */
    private function modHidden(string $page): string
    {
        return Html::hidden($page, $this->lang(), true).sprintf('<input type="hidden" name="_basis" value="%s">', $this->e($this->r->base));
    }

    /** Foren, in die verschoben werden kann (die die Person moderiert). */
    private function boardOptions(?int $except = null): string
    {
        $out = '';
        foreach ($this->forum->nodes($this->r->site) as $node) {
            if ($node->type === NodeType::Board && $node->id !== $except && $this->moderates($node)) {
                $depth = count($this->forum->ancestors($this->r->site, $node));
                $out .= sprintf('<option value="%d">%s%s</option>', $node->id, str_repeat('— ', $depth), $this->e($node->titleIn($this->lang())));
            }
        }

        return $out;
    }

    // ── Themenliste ─────────────────────────────────────────────

    private function threadCheckbox(Thread $thread): string
    {
        return sprintf('<input type="checkbox" class="forum-auswahl" name="themen[]" value="%d" form="forum-auswahl-themen" aria-label="%s">', $thread->id,
            $this->e($this->t('Thema «:title» auswählen', ['title' => $thread->title])));
    }

    /** Die Leiste unter der Themenliste: Aktion für die angekreuzten Themen. */
    private function threadSelectionBar(Node $node, string $page): string
    {
        $options = '';
        foreach (ModerationRequests::THREAD_ACTIONS as $key => $label) {
            if (! in_array($key, ['wiederherstellen', 'loeschen'], true)) {
                $options .= sprintf('<option value="%s">%s</option>', $key, $this->e($this->t($label)));
            }
        }
        $boards = $this->boardOptions($node->id);

        return sprintf('<form id="forum-auswahl-themen" class="forum-auswahlleiste" method="post" action="%s" data-insel="auswahl" data-insel-text="%s">%s<p class="forum-auswahlleiste__zahl" data-auswahl-zahl>%s</p>'
            .'<label>%s <select name="aktion" required><option value="">%s</option>%s</select></label>%s<label>%s <input type="text" name="grund" maxlength="500"></label>'
            .'<button type="submit" class="forum-button">%s</button></form>',
            $this->e(route('basalto.forum.moderate.threads', absolute: false)), $this->e($this->t(':count ausgewählt')), $this->modHidden($page), $this->e($this->t('Ausgewählte Themen')),
            $this->e($this->t('Aktion')), $this->e($this->t('Bitte wählen')), $options,
            $boards === '' ? '' : sprintf('<label>%s <select name="ziel"><option value="">—</option>%s</select></label><label class="community-check"><input type="checkbox" name="verweis" value="1" checked> %s</label>',
                $this->e($this->t('Ziel beim Verschieben oder Kopieren')), $boards, $this->e($this->t('Verweis im alten Forum lassen'))),
            $this->e($this->t('Grund (freiwillig)')), $this->e($this->t('Ausführen')));
    }

    /** Ankündigungen (FO-16) über jeder Themenliste — aus allen Foren, die die Person lesen darf. */
    private function announcements(Node $current): string
    {
        $site = $this->r->site;
        $readable = $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, $this->customer, Forum::READ, $n))->keys()->all();
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_announcement', true)->where('is_disabled', false)
            ->whereNull('moved_to_id')->whereIn('node_id', $readable)->orderByDesc('last_posted_at')->limit(5)->get();
        if ($threads->isEmpty()) {
            return '';
        }

        return sprintf('<section class="forum-section forum-section--ankuendigung" aria-label="%s"><h2>%s%s</h2><ol class="forum-list">%s</ol></section>', $this->e($this->t('Ankündigungen')),
            Markup::icon('megaphone'), $this->e($this->t('Ankündigungen')), $this->threadRows(array_values($threads->all()), true, $this->moderates($current)));
    }

    // ── Thema ───────────────────────────────────────────────────

    /**
     * Die Moderation am Thema (TB 47) als Karte am Rand: Kacheln für einen Klick (schliessen, anheften, ankündigen,
     * empfehlen — die aktive dunkel, mit dem Rückweg darunter), Klappen für Verschieben, Kopieren, Zusammenführen und
     * Papierkorb, dazu der Schalter «Beiträge auswählen».
     */
    private function threadMenu(Thread $thread, Node $node): string
    {
        $page = $this->r->url('thema/'.$thread->pathSegment());
        $action = $this->e(route('basalto.forum.moderate.threads', absolute: false));
        $hidden = fn (string $aktion): string => $this->modHidden($page).sprintf('<input type="hidden" name="themen[]" value="%d"><input type="hidden" name="aktion" value="%s">', $thread->id, $aktion);
        $tile = fn (string $aktion, string $icon, string $label, string $undo = '', string $class = ''): string => sprintf('<form method="post" action="%s">%s<button type="submit" class="forum-kachel%s">%s<span>%s%s</span></button></form>',
            $action, $hidden($aktion), $class, Markup::icon($icon), $this->e($this->t($label)), $undo === '' ? '' : '<small>'.$this->e($this->t($undo)).'</small>');
        $tiles = ($thread->is_disabled ? $tile('freischalten', 'check', 'Freischalten', '', ' forum-kachel--gut') : '')
            .($thread->is_closed ? $tile('oeffnen', 'lock', 'Geschlossen', 'öffnen', ' ist-aktiv') : $tile('schliessen', 'lock', 'Schliessen'))
            .($thread->is_sticky ? $tile('loesen', 'pin', 'Angeheftet', 'lösen', ' ist-aktiv') : $tile('anheften', 'pin', 'Anheften'))
            .($thread->is_announcement ? $tile('ankuendigung_aus', 'megaphone', 'Ankündigung', 'beenden', ' ist-aktiv') : $tile('ankuendigen', 'megaphone', 'Als Ankündigung'))
            .($thread->is_featured ? $tile('nicht_empfehlen', 'star', 'Empfohlen', 'zurücknehmen', ' ist-aktiv') : $tile('empfehlen', 'star', 'Empfehlen'));
        $flap = fn (string $icon, string $label, string $form, string $class = ''): string => sprintf('<details class="forum-klappe%s"><summary>%s %s</summary>%s</details>', $class, Markup::mini($icon), $this->e($this->t($label)), $form);
        $boards = $this->boardOptions($node->id);
        $flaps = ($boards === '' ? '' : $flap('move', 'Verschieben', sprintf('<form method="post" action="%s" class="forum-moderation__form">%s<label>%s <select name="ziel" required>%s</select></label><label class="community-check"><input type="checkbox" name="verweis" value="1" checked> %s</label><button type="submit" class="forum-aktion">%s</button></form>',
            $action, $hidden('verschieben'), $this->e($this->t('Verschieben nach')), $boards, $this->e($this->t('Verweis im alten Forum lassen')), $this->e($this->t('Verschieben')))))
            .$flap('copy', 'Kopieren', sprintf('<form method="post" action="%s" class="forum-moderation__form">%s<label>%s <select name="ziel" required>%s</select></label><button type="submit" class="forum-aktion">%s</button></form>',
                $action, $hidden('kopieren'), $this->e($this->t('Kopieren nach')), $this->boardOptions(), $this->e($this->t('Kopieren'))))
            .$flap('merge', 'Zusammenführen', sprintf('<form method="post" action="%s" class="forum-moderation__form">%s<label>%s <input type="text" name="ziel_thema" required inputmode="numeric" placeholder="%s"></label><button type="submit" class="forum-aktion">%s</button></form>',
                $action, $hidden('zusammenfuehren'), $this->e($this->t('In Thema aufgehen lassen')), $this->e($this->t('Nummer oder Adresse')), $this->e($this->t('Zusammenführen'))))
            .$flap('trash', 'In den Papierkorb', sprintf('<form method="post" action="%s" class="forum-moderation__form">%s<label>%s <input type="text" name="grund" maxlength="500"></label><button type="submit" class="forum-aktion forum-aktion--gefahr">%s %s</button></form>',
                $action, $hidden('papierkorb'), $this->e($this->t('Grund (freiwillig)')), Markup::mini('trash'), $this->e($this->t('In den Papierkorb'))), ' forum-klappe--gefahr');

        return sprintf('<section class="forum-karte forum-moderation" id="forum-moderation" aria-labelledby="forum-moderation-titel"><h2 id="forum-moderation-titel">%s%s<small>%s</small></h2><div class="forum-kacheln">%s</div><div class="forum-klappen">%s</div>'
            .'<label class="forum-schalter forum-schalter--leise"><input type="checkbox" id="forum-beitraege-waehlen"><span class="forum-schalter__bahn" aria-hidden="true"></span>%s</label></section>',
            Markup::icon('shield'), $this->e($this->t('Moderation')), $this->e($this->t('Thema')), $tiles, $flaps, $this->e($this->t('Beiträge auswählen')));
    }

    private function postCheckbox(Post $post): string
    {
        return sprintf('<label class="forum-auswahl-label"><input type="checkbox" class="forum-auswahl" name="beitraege[]" value="%d" form="forum-auswahl-beitraege"> <span class="visually-hidden">%s</span></label>',
            $post->id, $this->e($this->t('Beitrag auswählen')));
    }

    private function postSelectionBar(Thread $thread, Node $node): string
    {
        $options = '';
        foreach (ModerationRequests::POST_ACTIONS as $key => $label) {
            if (! in_array($key, ['wiederherstellen', 'loeschen'], true)) {
                $options .= sprintf('<option value="%s">%s</option>', $key, $this->e($this->t($label)));
            }
        }

        return sprintf('<form id="forum-auswahl-beitraege" class="forum-auswahlleiste" method="post" action="%s" data-insel="auswahl" data-insel-text="%s">%s<p class="forum-auswahlleiste__zahl" data-auswahl-zahl>%s</p>'
            .'<label>%s <select name="aktion" required><option value="">%s</option>%s</select></label><label>%s <input type="text" name="titel" maxlength="120"></label>'
            .'<label>%s <input type="text" name="grund" maxlength="500"></label><button type="submit" class="forum-button">%s</button></form>',
            $this->e(route('basalto.forum.moderate.posts', absolute: false)), $this->e($this->t(':count ausgewählt')), $this->modHidden($this->r->url('thema/'.$thread->pathSegment())), $this->e($this->t('Ausgewählte Beiträge')),
            $this->e($this->t('Aktion')), $this->e($this->t('Bitte wählen')), $options, $this->e($this->t('Titel beim Teilen')), $this->e($this->t('Grund (freiwillig)')), $this->e($this->t('Ausführen')));
    }

    /**
     * Am Beitrag für Moderierende (TB 47): wartet er oder ist er gemeldet, ein Streifen oben mit dem Entscheid
     * (Freischalten, Papierkorb, Meldungen zurückweisen) und Verwarnen/«Als Spammer entfernen»; sonst stehen Verwarnen
     * und Spammer im Menü «…» des Beitrags.
     *
     * @return array{strip: string, menu: string}
     */
    private function postModeration(Post $post, Thread $thread, int $openReports): array
    {
        $lang = $this->lang();
        $page = $this->r->url('thema/'.$thread->pathSegment());
        $tools = '';
        $isTeam = $post->customer_id !== null && CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $post->customer_id)->whereNotNull('user_id')->exists();
        if ($post->customer_id !== null && $post->customer_id !== $this->customer?->id && ! $isTeam && $this->forum->moderatesSomewhere($this->r->site, $this->customer)) {
            $days = app(ModerationSettings::class)->int($this->r->site, 'warning_days');
            $tools = sprintf('<details class="forum-melden"><summary>%s %s</summary><form method="post" action="%s" class="forum-moderation__form">%s<input type="hidden" name="konto" value="%d"><input type="hidden" name="beitrag" value="%d">'
                .'<label>%s <input type="text" name="grund" required minlength="3" maxlength="200"></label><label>%s <input type="number" name="punkte" min="0" max="100" value="1" required></label>'
                .'<label>%s <input type="number" name="tage" min="0" max="3650" value="%d"></label><button type="submit" class="forum-aktion">%s</button></form></details>',
                Markup::mini('alert'), $this->e($this->t('Verwarnen')), $this->e(route('basalto.forum.moderate.warn', absolute: false)), $this->modHidden($page.'#beitrag-'.$post->id), $post->customer_id, $post->id,
                $this->e($this->t('Grund')), $this->e($this->t('Punkte')), $this->e($this->t('Gültig (Tage, 0 = immer)')), $days, $this->e($this->t('Verwarnen')));
            $tools .= sprintf('<details class="forum-melden"><summary class="forum-aktion--gefahr">%s %s</summary><form method="post" action="%s" class="forum-moderation__form">%s<input type="hidden" name="konto" value="%d"><p>%s</p>'
                .'<button type="submit" class="forum-aktion forum-aktion--gefahr">%s</button></form></details>',
                Markup::mini('ban'), $this->e($this->t('Als Spammer entfernen')), $this->e(route('basalto.forum.moderate.spammer', absolute: false)), $this->modHidden($page), $post->customer_id,
                $this->e($this->t('Das Konto wird ohne Ende gesperrt, alle seine Themen und Beiträge kommen in den Papierkorb, offene Meldungen sind erledigt.')), $this->e($this->t('Jetzt entfernen')));
        }
        $strip = '';
        if ($post->is_disabled) {
            $posts = route('basalto.forum.moderate.posts', absolute: false);
            $strip .= sprintf('<p class="forum-post__wartet">%s %s</p><div class="forum-post__aktionen">%s%s%s</div>', Markup::mini('clock'), $this->e($this->t('Wartet auf die Moderation')),
                Activity::button($posts, ['beitraege[]' => $post->id, 'aktion' => 'freischalten', '_basis' => $this->r->base], $this->t('Freischalten'), $page, $lang, false, 'check', 'gut'),
                Activity::button($posts, ['beitraege[]' => $post->id, 'aktion' => 'papierkorb', '_basis' => $this->r->base], $this->t('In den Papierkorb'), $page, $lang, false, 'trash', 'gefahr'), $tools);
        }
        if ($openReports > 0) {
            $reports = CommunityReport::query()->withoutGlobalScopes()->where('subject_type', Moderation::REPORT_TYPE)->where('subject_id', $post->id)->where('status', CommunityReport::OPEN)->get();
            $reasons = implode(', ', array_unique(array_map(fn (CommunityReport $r): string => app(Reports::class)->reasonLabel(Moderation::REPORT_TYPE, $r->reason, $lang), $reports->all())));
            $notes = implode('', array_map(fn (string $n): string => '<li>'.$this->e($n).'</li>', array_values(array_filter($reports->pluck('note')->all()))));
            $decide = fn (string $decision, string $label, string $icon, string $variant): string => Activity::button(route('basalto.forum.moderate.report', absolute: false), ['id' => $post->id, 'entscheid' => $decision, '_basis' => $this->r->base], $this->t($label), $page, $lang, false, $icon, $variant);
            $strip .= sprintf('<div class="forum-post__meldungen"><p>%s <span class="forum-badge forum-badge--gemeldet">%s</span> %s</p>%s</div><div class="forum-post__aktionen">%s%s%s</div>',
                Markup::mini('flag'), $this->e(trans_choice(':count Meldung|:count Meldungen', $openReports, ['count' => $openReports], $lang)), $this->e($reasons), $notes === '' ? '' : '<ul>'.$notes.'</ul>',
                $decide(CommunityReport::HIDDEN, 'In den Papierkorb', 'trash', 'gefahr'), $decide(CommunityReport::KEPT, 'Meldungen zurückweisen', 'check', ''), $post->is_disabled ? '' : $tools);
        }

        // TB 52: Bearbeiten sperren oder wieder erlauben
        $lock = Activity::button(route('basalto.forum.moderate.posts', absolute: false), ['beitraege[]' => $post->id, 'aktion' => $post->is_edit_locked ? 'entsperren' : 'sperren', '_basis' => $this->r->base],
            $this->t($post->is_edit_locked ? 'Bearbeiten erlauben' : 'Bearbeiten sperren'), $page.'#beitrag-'.$post->id, $lang, false, $post->is_edit_locked ? 'unlock' : 'lock');

        return $strip === '' ? ['strip' => '', 'menu' => '<hr>'.$lock.$tools]
            : ['strip' => sprintf('<div class="forum-post__moderation%s">%s</div>', $openReports > 0 && ! $post->is_disabled ? ' forum-post__moderation--meldung' : '', $strip), 'menu' => '<hr>'.$lock];
    }

    /** «Melden» für Angemeldete (nicht am eigenen Beitrag) — im Menü «…» des Beitrags. */
    private function reportForm(Post $post, Thread $thread): string
    {
        if ($this->customer === null || $post->customer_id === $this->customer->id) {
            return '';
        }
        $lang = $this->lang();
        $id = 'melden-'.$post->id;
        $reasons = '';
        foreach (app(Reports::class)->reasons(Moderation::REPORT_TYPE, $lang) as $key => $label) {
            $reasons .= sprintf('<label class="community-check"><input type="radio" name="reason" value="%s" required> %s</label>', $this->e($key), $this->e($label));
        }

        return sprintf('<details class="forum-melden"><summary>%s %s</summary><form method="post" action="%s" class="forum-moderation__form">%s%s<input type="hidden" name="subject" value="%s"><input type="hidden" name="id" value="%d"><input type="hidden" name="_anker" value="beitrag-%d">'
            .'<fieldset><legend>%s</legend>%s</fieldset><label for="%s-notiz">%s</label><textarea id="%11$s-notiz" name="note" rows="2" maxlength="500"></textarea>'
            .'<button type="submit" class="forum-aktion">%s</button><p class="form-help">%s</p></form></details>',
            Markup::mini('flag'), $this->e($this->t('Melden')), $this->e(route('basalto.community.report', absolute: false)), Html::hidden($this->r->url('thema/'.$thread->pathSegment()), $lang, true), Html::honeypot(),
            Moderation::REPORT_TYPE, $post->id, $post->id, $this->e($this->t('Was stimmt nicht?')), $reasons, $id, $this->e($this->t('Hinweis für die Moderation (freiwillig)')),
            $this->e($this->t('Meldung senden')), $this->e($this->t('Wer geschrieben hat, erfährt nicht, wer gemeldet hat.')));
    }

    // ── Die Liste /community/moderation ──────────────────────────

    private function moderationPage(): MountResult
    {
        $site = $this->r->site;
        $lang = $this->lang();
        $title = $this->t('Moderation');
        $crumbs = [['label' => $title, 'href' => $this->r->url('moderation')]];
        if ($this->customer === null) {
            return $this->page($this->loginNote(), $title, $crumbs, null, true, true);
        }
        if (! $this->forum->moderatesSomewhere($site, $this->customer)) {
            return $this->denied();
        }
        $nodes = $this->forum->moderatedNodes($site, $this->customer);
        $views = ['meldungen' => [$this->t('Meldungen'), 'flag'], 'freischaltung' => [$this->t('Freischaltung'), 'clock'], 'papierkorb' => [$this->t('Papierkorb'), 'trash']];
        $view = array_key_exists((string) $this->r->query('ansicht'), $views) ? (string) $this->r->query('ansicht') : 'meldungen';
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('site_id', $site->id)->whereIn('node_id', $nodes);
        // TB 47: offene Fälle je Ansicht als Zähler an den Reitern
        $reportedIds = array_map(static fn (array $g): int => $g['id'], app(Reports::class)->pending($site->id, [Moderation::REPORT_TYPE], 500));
        $counts = [
            'meldungen' => $reportedIds === [] ? 0 : Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', $reportedIds)->whereIn('thread_id', (clone $threads)->select('id'))->count(),
            'freischaltung' => Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', true)->whereIn('thread_id', (clone $threads)->select('id'))->count(),
            'papierkorb' => 0,
        ];
        $tabs = '';
        foreach ($views as $key => [$label, $icon]) {
            $tabs .= sprintf('<li><a href="%s"%s>%s %s%s</a></li>', $this->e($this->r->url('moderation', ['ansicht' => $key === 'meldungen' ? null : $key])), $key === $view ? ' aria-current="page"' : '',
                Markup::mini($icon), $this->e($label), $counts[$key] > 0 ? ' <b class="forum-glocke__zahl">'.$counts[$key].'</b>' : '');
        }
        $page = $this->r->url('moderation', ['ansicht' => $view === 'meldungen' ? null : $view]);
        $items = '';
        $people = null;
        if ($view === 'meldungen') {
            $groups = app(Reports::class)->pending($site->id, [Moderation::REPORT_TYPE], 100);
            $posts = Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->whereIn('id', array_map(static fn (array $g): int => $g['id'], $groups))->whereIn('thread_id', (clone $threads)->select('id'))->get()->keyBy('id');
            $people = new People($site, $posts->pluck('customer_id')->all());
            foreach ($groups as $group) {
                $post = $posts->get($group['id']);
                if ($post === null) {
                    continue;
                }
                $reasons = implode(', ', array_unique(array_map(fn (CommunityReport $r): string => app(Reports::class)->reasonLabel(Moderation::REPORT_TYPE, $r->reason, $lang), $group['reports'])));
                $decide = fn (string $decision, string $label, string $icon, string $variant): string => Activity::button(route('basalto.forum.moderate.report', absolute: false), ['id' => $post->id, 'entscheid' => $decision, '_basis' => $this->r->base], $this->t($label), $page, $lang, false, $icon, $variant);
                $notes = array_values(array_filter(array_map(static fn (CommunityReport $r): ?string => $r->note, $group['reports'])));
                $items .= $this->caseItem($post, $people, sprintf('<span class="forum-badge forum-badge--gemeldet">%s</span> %s%s', $this->e(trans_choice(':count Meldung|:count Meldungen', $group['count'], ['count' => $group['count']], $lang)), $this->e($reasons),
                    $notes === [] ? '' : ' · '.$this->e(implode(' · ', $notes))),
                    $decide(CommunityReport::HIDDEN, 'In den Papierkorb', 'trash', 'gefahr').$decide(CommunityReport::KEPT, 'Meldungen zurückweisen', 'check', ''), true, 'flag', 'forum-sechseck--fehler');
            }
        } elseif ($view === 'freischaltung') {
            $posts = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', true)->whereIn('thread_id', (clone $threads)->select('id'))->orderBy('id')->limit(100)->get();
            $people = new People($site, $posts->pluck('customer_id')->all());
            foreach ($posts as $post) {
                $items .= $this->caseItem($post, $people, '<span class="forum-badge forum-badge--wartet">'.$this->e($this->t('Wartet auf die Moderation')).'</span>',
                    Activity::button(route('basalto.forum.moderate.posts', absolute: false), ['beitraege[]' => $post->id, 'aktion' => 'freischalten', '_basis' => $this->r->base], $this->t('Freischalten'), $page, $lang, false, 'check', 'gut')
                    .Activity::button(route('basalto.forum.moderate.posts', absolute: false), ['beitraege[]' => $post->id, 'aktion' => 'papierkorb', '_basis' => $this->r->base], $this->t('In den Papierkorb'), $page, $lang, false, 'trash', 'gefahr'), true, 'clock', 'forum-sechseck--wartet');
            }
        } else {
            $trashedThreads = (clone $threads)->onlyTrashed()->whereNull('moved_to_id')->orderByDesc('deleted_at')->limit(50)->get();
            $posts = Post::query()->withoutGlobalScope(SiteScope::class)->onlyTrashed()->where('site_id', $site->id)->whereIn('thread_id', (clone $threads)->whereNull('deleted_at')->select('id'))->orderByDesc('deleted_at')->limit(50)->get();
            $people = new People($site, [...$trashedThreads->pluck('customer_id')->all(), ...$posts->pluck('customer_id')->all()]);
            $restore = fn (string $kind, int $id): string => Activity::button(route($kind === 'thema' ? 'basalto.forum.moderate.threads' : 'basalto.forum.moderate.posts', absolute: false),
                [($kind === 'thema' ? 'themen[]' : 'beitraege[]') => $id, 'aktion' => 'wiederherstellen', '_basis' => $this->r->base], $this->t('Wiederherstellen'), $page, $lang, false, 'restore')
                .Activity::button(route($kind === 'thema' ? 'basalto.forum.moderate.threads' : 'basalto.forum.moderate.posts', absolute: false),
                    [($kind === 'thema' ? 'themen[]' : 'beitraege[]') => $id, 'aktion' => 'loeschen', '_basis' => $this->r->base], $this->t('Endgültig löschen'), $page, $lang, false, 'trash', 'gefahr');
            foreach ($trashedThreads as $thread) {
                $items .= sprintf('<li class="forum-fall"><span class="forum-sechseck" aria-hidden="true">%s</span><p class="forum-fall__kopf"><b>%s</b> · %s · %s%s</p><div class="forum-post__aktionen">%s</div></li>', Markup::icon('folder'), $this->e($thread->title),
                    $this->e($this->t('Thema von :name', ['name' => $people->name($thread->customer_id, $lang)])), Markup::time($thread->deleted_at, $lang),
                    $thread->trash_reason === null ? '' : ' · '.$this->e($thread->trash_reason), $restore('thema', $thread->id));
            }
            foreach ($posts as $post) {
                $items .= $this->caseItem($post, $people, $post->trash_reason === null ? '' : $this->e($post->trash_reason), $restore('beitrag', $post->id), false, 'trash');
            }
        }
        $list = $items === '' ? sprintf('<section class="forum-section"><div class="forum-leer"><span class="forum-sechseck forum-sechseck--ok" aria-hidden="true">%s</span><p>%s</p></div></section>', Markup::icon('check'), $this->e($this->t('Nichts zu tun — hier ist alles erledigt.')))
            : sprintf('<section class="forum-section"><ol class="forum-faelle">%s</ol></section>', $items);

        return $this->page($this->bar().sprintf('<nav class="forum-filter" aria-label="%s"><ul>%s</ul></nav>', $this->e($title), $tabs).$list, $title, $crumbs, null, true, true);
    }

    /** Ein Fall in der Moderationsliste: Art im Sechseck, Thema, Auszug, wer, wann, Aktionen. */
    private function caseItem(Post $post, People $people, string $badge, string $actions, bool $link = true, string $icon = 'chat', string $tone = ''): string
    {
        $lang = $this->lang();
        $thread = Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->find($post->thread_id);
        $title = $thread === null ? '' : ($link && ! $thread->trashed() ? sprintf('<a href="%s">%s</a>', $this->e($this->r->url('thema/'.$thread->pathSegment()).'#beitrag-'.$post->id), $this->e($thread->title)) : $this->e($thread->title));

        return sprintf('<li class="forum-fall"><span class="forum-sechseck %s" aria-hidden="true">%s</span><p class="forum-fall__kopf"><b>%s</b> · %s · %s</p><p>%s</p><blockquote>%s</blockquote><div class="forum-post__aktionen">%s</div></li>',
            $tone, Markup::icon($icon), $title, $this->e($people->name($post->customer_id, $lang)), Markup::time($post->created_at, $lang), $badge, $this->e(mb_substr(RichText::toText($post->body), 0, 300)), $actions);
    }
}
