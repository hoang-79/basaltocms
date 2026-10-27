<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\CommunityAttachment;
use Basalto\Core\Community\CommunityNotifications;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRichText;
use Basalto\Core\Community\CommunityTerms;
use Basalto\Core\Community\Conversations;
use Basalto\Core\Community\Islands;
use Basalto\Core\Community\MountHandler;
use Basalto\Core\Community\MountRequest;
use Basalto\Core\Community\MountResult;
use Basalto\Core\Community\ProfileForm;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\Reactions;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Community\Subscriptions;
use Basalto\Core\Community\Typing;
use Basalto\Core\Community\VisitorAccess;
use Basalto\Core\Content\RichText;
use Basalto\Core\Content\Share;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\LoginForm;
use Basalto\Core\Live\Live;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\ForumLive;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Prefix;
use Basalto\Modules\Forum\Models\Revision;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\NodeType;
use Basalto\Modules\Forum\ReadTracker;
use Basalto\Modules\Forum\Tags;
use Basalto\Modules\Forum\ThreadFields;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Das Forum unter seiner Einhängeseite (CR-006 FO-04, FO-05), Vorbild WoltLab:
 *
 * - `/community` — Forenübersicht: Kategorien mit ihren Foren, Zähler, letzter Beitrag;
 * - `/community/<forum>` — Themenliste (angeheftete oben), Unterforen, «Neues Thema»;
 * - `/community/<forum>/neu` — Thema eröffnen;
 * - `/community/thema/<id>-<slug>` — Thema mit Beiträgen und Schnellantwort;
 * - `/community/konto` — Anmeldung bzw. Profil;
 * - `/community/moderation` — Meldungen, Freischaltung, Papierkorb für Moderierende (TB 39, {@see ModeratesInPlace});
 * - `/community/mitglied/<id>-<name>`, `mitglieder`, `team`, `aktivitaet`, `konversationen` — Profile und Konversationen
 *   (TB 40, {@see CommunityPages}).
 *
 * Alles serverseitig und ohne Skript bedienbar; die Editor-Insel macht das Schreiben bequemer. Ansichten für Gäste
 * dürfen in den Seiten-Cache, alles mit Formular oder Konto nicht.
 */
final class ForumMount implements MountHandler
{
    use CommunityPages;
    use FindingPages;
    use ModeratesInPlace;

    public const THREADS_PER_PAGE = 20;

    public const POSTS_PER_PAGE = 15;

    private MountRequest $r;

    private ?Customer $customer;

    /** @var array<int, Prefix>|null Präfixe der Site, einmal je Anfrage */
    private ?array $prefixes = null;

    /** @var list<int> ungelesene Foren dieser Anfrage (inkl. Vorfahren) */
    private array $unreadNodes = [];

    /** Ungelesene Themen der angemeldeten Person, einmal je Anfrage gezählt */
    private ?int $unreadThreads = null;

    public function __construct(private readonly Forum $forum, private readonly Profiles $profiles, private readonly ReadTracker $reads, private readonly Subscriptions $subscriptions,
        private readonly Widgets $widgets, private readonly Reactions $reactions) {}

    public function handle(MountRequest $request): MountResult
    {
        $this->r = $request;
        $this->customer = CustomerAuth::current();
        if ($this->customer !== null && ($profile = $this->profiles->of($this->customer)) !== null) {
            $this->profiles->touch($profile);
        }
        $segments = $request->segments;

        return match (true) {
            $segments === [] => $this->index(),
            $segments === ['konto'] => $this->account(),
            $segments === ['ungelesen'] => $this->listing('ungelesen'),
            $segments === ['aktuell'] => $this->listing('aktuell'),
            $segments === ['abonniert'] => $this->listing('abonniert'),
            $segments === ['benachrichtigungen'] => $this->notifications(),
            $segments === ['moderation'] => $this->moderationPage(),
            // TB 41: Suche, Schlagwörter, Feeds
            $segments === ['suche'] => $this->searchPage(),
            $segments === ['neu'] => $this->chooseBoard(),
            $segments === ['feed'] => $this->feed(),
            count($segments) === 2 && $segments[0] === 'tag' => $this->tagPage($segments[1]),
            count($segments) === 2 && $segments[1] === 'feed' => $this->feed($segments[0]),
            // TB 40: Profile, Mitglieder, Konversationen
            $segments === ['mitglieder'] => $this->members(),
            $segments === ['team'] => $this->team(),
            $segments === ['aktivitaet'] => $this->followedActivity(),
            $segments === ['konversationen'] => $this->conversationList(),
            $segments === ['konversationen', 'neu'] => $this->newConversation(),
            count($segments) === 2 && $segments[0] === 'konversationen' => $this->showConversation($segments[1]),
            count($segments) === 2 && $segments[0] === 'mitglied' => $this->memberProfile($segments[1]),
            count($segments) === 2 && $segments[0] === 'benachrichtigungen' => $this->followNotification($segments[1]),
            count($segments) === 3 && $segments[0] === 'thema' && $segments[2] === 'ungelesen' => $this->firstUnread($segments[1]),
            // CR-008: neue Beiträge seit einem Beitrag — für die Live-Aktualisierung (JSON)
            count($segments) === 3 && $segments[0] === 'thema' && $segments[2] === 'neu' => $this->threadUpdates($segments[1]),
            count($segments) === 2 && $segments[0] === 'thema' => $this->thread($segments[1]),
            count($segments) === 1 && $segments[0] !== 'beitrag' => $this->board($segments[0]),
            count($segments) === 2 && $segments[1] === 'neu' => $this->newThread($segments[0]),
            count($segments) === 2 && $segments[0] === 'beitrag' => $this->postRedirect($segments[1]),
            count($segments) === 3 && $segments[0] === 'beitrag' && $segments[2] === 'bearbeiten' => $this->editPost($segments[1]),
            count($segments) === 3 && $segments[0] === 'beitrag' && $segments[2] === 'verlauf' => $this->history($segments[1]),
            default => MountResult::notFound(),
        };
    }

    private function lang(): string
    {
        return $this->r->locale;
    }

    /** @param  array<string, string|int>  $replace */
    private function t(string $text, array $replace = []): string
    {
        return (string) __($text, $replace, $this->lang());
    }

    private function e(mixed $value): string
    {
        return Markup::e($value);
    }

    /** @param  list<array{label: string, href: string}>  $crumbs */
    private function page(string $html, ?string $title = null, array $crumbs = [], ?string $description = null, bool $personal = false, bool $noindex = false, ?string $image = null): MountResult
    {
        // CR-011 TB 60: ohne Zustimmung zu den Bedingungen zuerst das Fenster; für Konten ist die Community dahinter gesperrt
        $terms = $this->termsModal();
        $html = '<div class="forum"'.($terms !== '' && $this->customer !== null ? ' inert' : '').'>'.$this->topNotes().$html.'</div>'.$terms;

        return MountResult::page(Islands::attach($html), $title, $crumbs, $description, $personal || $this->customer !== null, $noindex, head: Styles::tag().$this->extraHead, image: $image);
    }

    /** CR-011 TB 60: das Fenster mit den Bedingungen der Community — leer mit gültiger Zustimmung oder ohne Pflicht. */
    private function termsModal(): string
    {
        $site = $this->r->site;
        $path = $this->r->url($this->r->segments === [] ? '' : implode('/', $this->r->segments));

        return app(CommunityTerms::class)->modal($site, $this->customer, $this->lang(), Html::hidden($path, $this->lang(), $this->customer !== null),
            route('basalto.community.terms.accept', [], false), route('basalto.community.terms.guest', [], false), $this->homeUrl());
    }

    /** Wohin «Nicht jetzt» führt: die Startseite in der Sprache der Seite. */
    private function homeUrl(): string
    {
        $site = $this->r->site;

        return $this->lang() === $site->default_locale ? '/' : '/'.$this->lang().'/';
    }

    /**
     * Der Navigator oben (TB 47): die Wege durchs Forum mit Symbol, die Suche, Nachrichten und Glocke mit Zählern, die
     * Moderation mit offenen Fällen und das eigene Profil. Darunter, wenn es welche gibt, die Werkzeuge der Seite.
     */
    private function bar(string $actions = '', string $head = ''): string
    {
        $current = implode('/', $this->r->segments);
        $ways = [['', 'home', 'Übersicht', 0], ['aktuell', 'zap', 'Neueste', 0]];
        if ($this->customer !== null) {
            array_push($ways, ['ungelesen', 'inbox', 'Ungelesen', $this->unreadCount()], ['abonniert', 'bookmark', 'Abonniert', 0], ['aktivitaet', 'user-plus', 'Gefolgt', 0]);
        }
        $ways[] = ['mitglieder', 'users', 'Mitglieder', 0];
        $list = '';
        foreach ($ways as [$path, $icon, $label, $count]) {
            $list .= sprintf('<li><a class="forum-nav__weg" href="%s"%s>%s<span>%s</span>%s</a></li>', $this->e($this->r->url($path)), $current === $path ? ' aria-current="page"' : '',
                Markup::icon($icon), $this->e($this->t($label)), $count > 0 ? ' <b class="forum-glocke__zahl forum-nav__zahl">'.$count.'</b>' : '');
        }
        // TB 71: Live-Suche — beim Tippen schlägt die Insel search.ts Themen vor; ohne Skript ein gewöhnliches Suchfeld
        $tools = sprintf('<form class="forum-nav__suche" role="search" method="get" action="%s"><label for="forum-nav-suche" class="visually-hidden">%s</label>%s<input id="forum-nav-suche" type="search" name="q" minlength="2" maxlength="100" placeholder="%2$s" data-suche="vorschlag" data-suche-url="%s" data-suche-titel="%s" data-suche-alle="%s" data-suche-leer="%s"></form>',
            $this->e($this->r->url('suche')), $this->e($this->t('Im Forum suchen')), Markup::icon('search'), $this->e(route('basalto.forum.live-search', [], false)),
            $this->e($this->t('Vorschläge aus dem Forum')), $this->e($this->t('Alle :count Treffer anzeigen')), $this->e($this->t('Keine Treffer — zur ausführlichen Suche')));
        if ($this->customer === null) {
            // Steht oben schon «Zum Schreiben anmelden», braucht es den Verweis nicht ein zweites Mal
            $tools .= $actions !== '' || $head !== '' ? '' : sprintf('<a class="forum-button forum-nav__anmelden" href="%s">%s %s</a>', $this->e($this->r->url('konto')),
                Markup::mini('login'), $this->e($this->t('Anmelden')));
        } else {
            $unread = app(CommunityNotifications::class)->unread($this->customer);
            // TB 40: Konversationen mit Zahl der ungelesenen; CR-008: beide Zähler zählen live mit
            $messages = count(app(Conversations::class)->unreadIds($this->customer));
            $counter = fn (int $count, string $field): string => sprintf('<b class="forum-glocke__zahl"%s%s>%s</b>', $count > 0 ? '' : ' hidden',
                app(Live::class)->attributes($this->r->site->id, 'konto.'.$this->customer->id, 'zaehler', ['feld' => $field]), $count > 0 ? $count : '');
            $tools .= $this->navButton('konversationen', 'chat', $this->t('Nachrichten'), $counter($messages, 'nachrichten'))
                .$this->navButton('benachrichtigungen', 'bell', $this->t('Benachrichtigungen'), $counter($unread, 'benachrichtigungen'), ' forum-glocke')
                .$this->moderationLink();
            $name = $this->profiles->displayName($this->customer);
            $tools .= sprintf('<a class="forum-nav__ich" href="%s" title="%s"%s>%s<span>%s</span></a>', $this->e($this->r->url('konto')), $this->e($this->t('Angemeldet als :name', ['name' => $name])),
                $current === 'konto' ? ' aria-current="page"' : '', Markup::avatar($this->profiles->of($this->customer), $name, 'klein'), $this->e($name));
        }
        $extra = $actions.$head;

        return sprintf('<nav class="forum-nav" aria-label="%s"><ul class="forum-nav__wege">%s</ul><div class="forum-nav__konto">%s</div></nav>', $this->e($this->t('Forum')), $list, $tools)
            .($extra === '' ? '' : '<div class="forum-werkzeuge">'.$extra.'</div>').$this->sanctionNote();
    }

    /** Ein Symbol-Knopf im Navigator — der Name für Screenreader im Text, damit der Zähler live mitgelesen wird. */
    private function navButton(string $path, string $icon, string $label, string $counter, string $class = ''): string
    {
        return sprintf('<a class="forum-nav__knopf%s" href="%s" title="%s"%s>%s<span class="visually-hidden">%3$s</span>%s</a>', $class, $this->e($this->r->url($path)), $this->e($label),
            implode('/', $this->r->segments) === $path ? ' aria-current="page"' : '', Markup::icon($icon), $counter);
    }

    /** Ungelesene Themen der angemeldeten Person (höchstens 99) — für den Navigator und den Puls, einmal je Anfrage. */
    private function unreadCount(): int
    {
        if ($this->customer === null) {
            return 0;
        }
        if ($this->unreadThreads !== null) {
            return $this->unreadThreads;
        }
        $site = $this->r->site;
        $readable = $this->visible($this->forum->nodes($site)->values())->filter(fn (Node $n): bool => $this->forum->can($site, $this->customer, Forum::READ, $n))->map(static fn (Node $n): int => $n->id)->all();
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereIn('node_id', $readable)->whereNull('moved_to_id')
            ->whereNotIn('id', $this->subscriptions->ignored($this->customer, 'forum_thread'))->where('last_posted_at', '>', now()->subDays(ReadTracker::HORIZON_DAYS))
            ->orderByDesc('last_posted_at')->limit(200)->get(['id', 'node_id', 'last_posted_at'])->all();

        return $this->unreadThreads = min(99, count($this->reads->unread($this->customer, array_values($threads))));
    }

    /** Ein Menü «…» als aufklappbares Popover — ohne Skript bedienbar. */
    private function menu(string $label, string $items, string $icon = 'dots', string $class = ''): string
    {
        return $items === '' ? '' : sprintf('<details class="forum-menue forum-menue--icon%s"><summary aria-label="%s" title="%2$s">%s</summary><div class="forum-menue__liste">%s</div></details>',
            $class, $this->e($label), Markup::icon($icon), $items);
    }

    private function menuLink(string $path, string $icon, string $label): string
    {
        return sprintf('<a href="%s">%s %s</a>', $this->e($this->r->url($path)), Markup::mini($icon), $this->e($label));
    }

    /**
     * Die Aktionen einer Übersicht oder eines Forums (TB 47): «+ Neues Thema», Abonnieren als Glocke und ein Menü «…»
     * mit Ignorieren, gelesen, RSS, Suche, Mitglieder.
     */
    private function headActions(?Node $node): string
    {
        $site = $this->r->site;
        $lang = $this->lang();
        $page = $node === null ? $this->r->url() : $this->r->url($node->slug);
        $new = match (true) {
            $node !== null && $node->type === NodeType::Board && $this->forum->canStart($site, $this->customer, $node) => $this->r->url($node->slug.'/neu'),
            $node === null && $this->customer !== null => $this->r->url('neu'),
            $this->customer === null => $this->r->url('konto'),
            default => null,
        };
        $watch = '';
        $menu = '';
        if ($this->customer !== null && $node !== null && $node->type === NodeType::Board) {
            [$watch, $ignore] = $this->subscribeToggle('forum', $node->id, $this->subscriptions->modeOf($this->customer, 'forum_node', $node->id), $page);
            $menu .= $ignore;
        }
        if ($this->customer !== null) {
            $menu .= Activity::button(route('basalto.forum.read', absolute: false), $node === null ? [] : ['forum' => $node->id], $node === null ? $this->t('Alles als gelesen markieren') : $this->t('Als gelesen markieren'), $page, $lang, false, 'check');
        }
        $menu .= $this->menuLink($node === null ? 'feed' : $node->slug.'/feed', 'rss', $this->t('RSS-Feed')).$this->menuLink('suche', 'search', $this->t('Suche im Forum'))
            .$this->menuLink('mitglieder', 'users', $this->t('Mitglieder'));

        return ($new === null ? '' : sprintf('<a class="forum-button" href="%s">%s %s</a>', $this->e($new), Markup::mini($this->customer === null && $node !== null ? 'login' : 'plus'),
            $this->e($this->customer === null && $node !== null ? $this->t('Zum Schreiben anmelden') : $this->t('Neues Thema'))))
            .$watch.$this->menu($this->t('Weitere Aktionen'), $menu);
    }

    // ── Forenübersicht ───────────────────────────────────────────

    private function index(): MountResult
    {
        $site = $this->r->site;
        $this->areas = ['forum'];
        $this->feedLink();
        $top = $this->visible($this->forum->children($site, null));
        $this->computeUnreadNodes();
        $lang = $this->lang();
        $html = $this->bar().$this->pulse().$this->liveNotice(ForumLive::OVERVIEW, $this->t('Neue Beiträge im Forum'));
        $groups = [];
        $loose = [];
        foreach ($top as $node) {
            if ($node->type === NodeType::Category) {
                $groups[] = [$node, array_values($this->visible($this->forum->children($site, $node->id))->all())];
            } else {
                $loose[] = $node;
            }
        }
        if ($loose !== []) {
            array_unshift($groups, [null, $loose]);
        }
        $latest = $this->latestThreads(array_merge(...array_map(static fn (array $g): array => $g[1], $groups)));
        $people = new People($site, array_merge(...array_map(static fn (array $list): array => array_map(static fn (Thread $t): ?int => $t->last_customer_id, $list), array_values($latest)) ?: [[]]));
        $main = '';
        foreach ($groups as [$category, $nodes]) {
            $main .= $this->section($category?->titleIn($lang), null, $nodes, $people, $category === null ? null : 'forum-kategorie-'.$category->id, $latest);
        }
        if ($top->isEmpty()) {
            $main .= sprintf('<p class="forum-empty">%s</p>', $this->e($this->t('Hier gibt es noch keine Foren.')));
        }
        $tags = Tags::popular($site, 15);
        $aside = $this->widgets->featured($site, $this->customer, $lang, $this->r->base)
            .$this->widgets->threads($site, $this->customer, $lang, $this->r->base)
            .$this->widgets->blog($site, $lang)
            .$this->widgets->threads($site, $this->customer, $lang, $this->r->base, true)
            .($tags === [] ? '' : sprintf('<section class="forum-kasten"><h2>%s%s</h2><p class="forum-schlagwoerter">%s</p></section>', Markup::icon('hash'), $this->e($this->t('Schlagwörter')), $this->tagLinks($tags)));

        return $this->page($html.sprintf('<div class="forum-spalten"><div class="forum-spalten__haupt">%s</div><aside class="forum-spalten__seite" aria-label="%s">%s</aside></div>%s',
            $main, $this->e($this->t('Rund um die Community')), $aside, $this->widgets->band($site, $lang, $this->r->base)));
    }

    /**
     * Der Puls über der Übersicht (TB 47): Begrüssung, was seit dem letzten Besuch neu ist, Kennzahlen, wer gerade da
     * ist, und die Aktionen. Für Gäste kommt er aus dem Seiten-Cache — so alt wie die Seite.
     */
    private function pulse(): string
    {
        $site = $this->r->site;
        $lang = $this->lang();
        $figures = $this->widgets->figures($site);
        $online = $figures['online'];
        $avatars = array_map(static fn (CommunityProfile $p): string => Markup::avatar($p, $p->name, 'klein'), array_slice($online, 0, 5));
        if ($this->customer === null) {
            $greeting = sprintf('<p class="forum-puls__gruss">%s<small>%s</small></p>', $this->e($this->t('Willkommen in der Community')), $this->e($this->t('Fragen stellen, Wissen teilen, gemeinsam weiterkommen.')));
        } else {
            $unread = $this->unreadCount();
            $greeting = sprintf('<p class="forum-puls__gruss">%s<small>%s</small></p>', $this->e($this->t('Willkommen zurück, :name', ['name' => $this->profiles->displayName($this->customer)])),
                $unread > 0 ? sprintf('<a class="forum-puls__neu" href="%s">%s %s</a>', $this->e($this->r->url('ungelesen')), Markup::mini('sparkles'),
                    $this->e(trans_choice(':count neues Thema seit Ihrem letzten Besuch|:count neue Themen seit Ihrem letzten Besuch', $unread, ['count' => $unread], $lang)))
                : $this->e($this->t('Alles gelesen — schön, dass Sie da sind.')));
        }
        $kpis = sprintf('<dl class="forum-kennzahlen"><div><dt>%s</dt><dd>%s</dd></div><div><dt>%s</dt><dd>%s</dd></div><div><dt>%s</dt><dd>%s</dd></div><div class="forum-kennzahl--stapel"><dt><span class="forum-puls__online">%s</span></dt><dd>%s%s</dd></div></dl>',
            $this->e($this->t('Themen')), Markup::compact($figures['threads'], $lang), $this->e($this->t('Beiträge')), Markup::compact($figures['posts'], $lang),
            $this->e($this->t('Mitglieder')), Markup::compact($figures['members'], $lang), $this->e($this->t('Gerade online')),
            $avatars === [] ? '' : Markup::stack($avatars, max(0, count($online) - 5)), count($online));

        return sprintf('<section class="forum-puls" aria-label="%s">%s%s<div class="forum-puls__knoepfe">%s</div></section>', $this->e($this->t('Die Community auf einen Blick')), $greeting, $kpis, $this->headActions(null));
    }

    /**
     * Die letzten zwei Themen je Forum (mit Unterforen) — rechts in jeder Zeile der Übersicht, wie bei WoltLab.
     *
     * @param  list<Node>  $nodes
     * @return array<int, list<Thread>>
     */
    private function latestThreads(array $nodes): array
    {
        $site = $this->r->site;
        $ignored = $this->subscriptions->ignored($this->customer, 'forum_thread');
        $out = [];
        foreach ($nodes as $node) {
            if ($node->type !== NodeType::Board || ! $this->forum->can($site, $this->customer, Forum::READ, $node)) {
                continue;
            }
            $ids = $this->forum->nodes($site)->filter(fn (Node $n): bool => $n->id === $node->id || in_array($node->id, array_map(static fn (Node $a): int => $a->id, $this->forum->ancestors($site, $n)), true))
                ->filter(fn (Node $n): bool => $this->forum->can($site, $this->customer, Forum::READ, $n))->keys()->all();
            $out[$node->id] = array_values(Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereIn('node_id', $ids)->where('is_disabled', false)
                ->whereNull('moved_to_id')->whereNotIn('id', $ignored)->orderByDesc('last_posted_at')->limit(2)->get()->all());
        }

        return $out;
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<int, list<Thread>>  $latest
     */
    private function section(?string $title, ?string $text, array $nodes, People $people, ?string $id = null, array $latest = []): string
    {
        if ($nodes === [] && $title === null) {
            return '';
        }
        $heading = $title === null ? '' : sprintf('<h2 class="forum-kategorie__titel" id="%s">%s<small>%s</small></h2>', $this->e($id), $this->e($title),
            $this->e(trans_choice(':count Forum|:count Foren', count($nodes), ['count' => count($nodes)], $this->lang())));
        $intro = $text !== null && $text !== '' ? sprintf('<p class="forum-section__text">%s</p>', $this->e($text)) : '';
        $rows = implode('', array_map(fn (Node $n): string => $this->nodeRow($n, $people, $latest[$n->id] ?? []), $nodes));
        $list = $rows === '' ? sprintf('<p class="forum-empty">%s</p>', $this->e($this->t('Noch keine Foren in dieser Kategorie.'))) : '<ul class="forum-list forum-list--foren">'.$rows.'</ul>';

        return sprintf('<section class="forum-kategorie"%s>%s<div class="forum-section">%s%s</div></section>', $id === null ? '' : ' aria-labelledby="'.$this->e($id).'"', $heading, $intro, $list);
    }

    /** @param  list<Thread>  $latest */
    private function nodeRow(Node $node, People $people, array $latest = []): string
    {
        $lang = $this->lang();
        $title = $node->titleIn($lang);
        $description = $node->descriptionIn($lang) === '' ? '' : '<p class="forum-row__text">'.$this->e($node->descriptionIn($lang)).'</p>';
        if ($node->type === NodeType::Link) {
            return sprintf('<li class="forum-row forum-row--link"><span class="forum-sechseck forum-sechseck--forum" aria-hidden="true">%s</span><div class="forum-row__main"><h3><a href="%s" rel="noopener">%s</a></h3>%s</div></li>',
                Markup::icon('link'), $this->e($node->link_url), $this->e($title), $description);
        }
        $subs = $this->visible($this->forum->children($this->r->site, $node->id))->filter(static fn (Node $c): bool => $c->type !== NodeType::Category);
        $subsHtml = $subs->isEmpty() ? '' : sprintf('<p class="forum-subs"><span class="visually-hidden">%s</span>%s</p>', $this->e($this->t('Unterforen:')),
            implode('', $subs->map(fn (Node $c): string => sprintf('<a href="%s">%s%s</a>', $this->e($c->type === NodeType::Link ? (string) $c->link_url : $this->r->url($c->slug)),
                Markup::mini($c->type === NodeType::Link ? 'link' : ($c->isClosed() ? 'lock' : 'folder')), $this->e($c->titleIn($lang))))->all()));
        $new = in_array($node->id, $this->unreadNodes, true);
        $items = '';
        foreach ($latest as $thread) {
            $items .= sprintf('<li>%s<div><a href="%s">%s%s</a><span>%s · %s</span></div></li>', $people->avatar($thread->last_customer_id, $lang, 'klein'),
                $this->e($this->r->url('thema/'.$thread->pathSegment().($this->customer !== null ? '/ungelesen' : ''))), $this->prefixBadge($thread->prefix_id), $this->e($thread->title),
                $this->e($people->name($thread->last_customer_id, $lang)), Markup::time($thread->last_posted_at, $lang));
        }
        $icon = (string) $node->setting('icon', $node->isClosed() ? 'lock' : 'chat');

        return sprintf('<li class="forum-row forum-row--forum%s"><span class="forum-sechseck %s"%s aria-hidden="true">%s</span><div class="forum-row__main"><h3><a href="%s">%s</a>%s</h3>%s%s</div>'
            .'<dl class="forum-row__kennzahlen"><div><dt>%s</dt><dd>%s</dd></div><div><dt>%s</dt><dd>%s</dd></div></dl><ol class="forum-row__letzte">%s</ol></li>',
            $new ? ' forum-row--neu' : '', $new ? 'forum-sechseck--neu' : 'forum-sechseck--forum', $new ? ' title="'.$this->e($this->t('Neue Beiträge')).'"' : '', Markup::icon($icon),
            $this->e($this->r->url($node->slug)), $this->e($title), $new ? ' <span class="forum-badge forum-badge--neu">'.$this->e($this->t('Neu')).'</span>' : '', $description, $subsHtml,
            $this->e($this->t('Themen')), Markup::compact($node->threads_count, $lang), $this->e($this->t('Beiträge')), Markup::compact($node->posts_count, $lang),
            $items === '' ? '<li class="forum-row__leer">'.$this->e($this->t('Noch keine Beiträge')).'</li>' : $items);
    }

    /**
     * Nur Knoten, die die Person sehen darf.
     *
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, Node>
     */
    private function visible(Collection $nodes): Collection
    {
        $ignored = $this->subscriptions->ignored($this->customer, 'forum_node');

        return $nodes->filter(fn (Node $n): bool => ! in_array($n->id, $ignored, true) && $this->forum->can($this->r->site, $this->customer, Forum::VIEW, $n))->values();
    }

    /** Foren mit ungelesenen Themen — auch ihre Vorfahren gelten als «neu». */
    private function computeUnreadNodes(): void
    {
        if ($this->customer === null) {
            return;
        }
        $site = $this->r->site;
        $out = [];
        foreach ($this->reads->unreadNodes($this->customer, $this->forum->nodes($site), $this->forum) as $nodeId) {
            $node = $this->forum->nodes($site)->get($nodeId);
            if ($node !== null) {
                array_push($out, ...array_map(static fn (array $s): int => $s[1], $this->forum->chain($site, $node)));
            }
        }
        $this->unreadNodes = array_values(array_unique($out));
    }

    // ── Themenliste ─────────────────────────────────────────────

    private function board(string $slug): MountResult
    {
        $site = $this->r->site;
        $node = $this->forum->bySlug($site, $slug);
        if ($node === null || $node->type === NodeType::Link) {
            return MountResult::notFound();
        }
        if (! $this->forum->can($site, $this->customer, Forum::VIEW, $node)) {
            return $this->denied();
        }
        $lang = $this->lang();
        $this->areasOf($node);
        if ($node->type === NodeType::Board) {
            $this->feedLink($node);
        }
        $crumbs = $this->crumbs($node);
        $children = array_values($this->visible($this->forum->children($site, $node->id))->all());
        $latest = $this->latestThreads($children);
        $people = new People($site, array_merge(...array_map(static fn (array $list): array => array_map(static fn (Thread $t): ?int => $t->last_customer_id, $list), array_values($latest)) ?: [[]]));
        $this->computeUnreadNodes();
        $html = $this->bar().$this->boardHero($node, count($children)).$this->liveNotice(ForumLive::node($node->id), $this->t('Neue Beiträge in diesem Forum'));
        if ($children !== []) {
            $html .= $this->section($this->t('Unterforen'), null, $children, $people, 'forum-unterforen', $latest);
        }
        if ($node->type === NodeType::Board) {
            $html .= $this->announcements($node).$this->threadList($node);
        }

        return $this->page($html, $node->titleIn($lang), $crumbs, $node->descriptionIn($lang) ?: null);
    }

    /** Kopf eines Forums (TB 47): Symbol im Sechseck, Beschreibung, Kennzahlen und Aktionen. */
    private function boardHero(Node $node, int $children): string
    {
        $lang = $this->lang();
        $description = $node->descriptionIn($lang);
        $figures = sprintf('<div><dt>%s</dt><dd>%s</dd></div><div><dt>%s</dt><dd>%s</dd></div>', $this->e($this->t('Themen')), Markup::compact($node->threads_count, $lang),
            $this->e($this->t('Beiträge')), Markup::compact($node->posts_count, $lang))
            .($children > 0 ? sprintf('<div><dt>%s</dt><dd>%d</dd></div>', $this->e($this->t('Unterforen')), $children) : '');
        $state = $node->isClosed() ? sprintf('<div class="forum-held__chips"><span class="forum-badge forum-badge--dunkel">%s %s</span></div>', Markup::mini('lock'), $this->e($this->t('Geschlossen'))) : '';

        return sprintf('<section class="forum-held" aria-label="%s"><div class="forum-held__kopf"><span class="forum-held__zeichen" aria-hidden="true">%s</span><div class="forum-held__text">%s%s</div></div>'
            .'<div class="forum-held__leiste"><dl class="forum-kennzahlen">%s</dl><div class="forum-held__knoepfe">%s</div></div></section>',
            $this->e($this->t('Über dieses Forum')), Markup::icon((string) $node->setting('icon', $node->isClosed() ? 'lock' : 'chat')), $state,
            $description === '' ? '' : '<p class="forum-held__lead">'.$this->e($description).'</p>', $figures, $node->type === NodeType::Board ? $this->headActions($node) : '');
    }

    /** Sortierungen der Themenliste — Schlüssel in der Adresse → Bezeichnung */
    private const SORTS = ['letzte' => 'Letzte Antwort', 'erstellt' => 'Erstellt', 'antworten' => 'Antworten', 'titel' => 'Titel'];

    private function threadList(Node $node): string
    {
        $site = $this->r->site;
        $lang = $this->lang();
        if (! $this->forum->can($site, $this->customer, Forum::READ, $node)) {
            return sprintf('<p class="forum-note">%s</p>', $this->e($this->t('Die Themen dieses Forums sind nur für Mitglieder mit Berechtigung lesbar.')));
        }
        $moderates = $this->moderates($node);
        $query = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('node_id', $node->id)
            ->when(! $moderates, static fn ($q) => $q->where('is_disabled', false))->where('is_announcement', false)
            ->where(static fn ($q) => $q->whereNull('moved_to_id')->orWhereNull('redirect_until')->orWhere('redirect_until', '>', now()))
            ->whereNotIn('id', $this->subscriptions->ignored($this->customer, 'forum_thread'));
        // Filter wie bei WoltLab: Präfix, Sprache, Status
        $prefixIds = array_values(array_map('intval', (array) $node->setting('prefixes', [])));
        $prefix = (int) $this->r->query('praefix');
        $prefix = in_array($prefix, $prefixIds, true) ? $prefix : 0;
        $language = in_array($this->r->query('sprache'), $site->localeCodes(), true) ? (string) $this->r->query('sprache') : '';
        $status = in_array($this->r->query('status'), ['erledigt', 'offen'], true) ? (string) $this->r->query('status') : '';
        $sort = array_key_exists((string) $this->r->query('sortierung'), self::SORTS) ? (string) $this->r->query('sortierung') : 'letzte';
        $query->when($prefix > 0, static fn ($q) => $q->where('prefix_id', $prefix))->when($language !== '', static fn ($q) => $q->where('locale', $language))
            ->when($status !== '', static fn ($q) => $q->where('is_done', $status === 'erledigt'));
        $params = ['praefix' => $prefix > 0 ? $prefix : null, 'sprache' => $language !== '' ? $language : null, 'status' => $status !== '' ? $status : null, 'sortierung' => $sort === 'letzte' ? null : $sort];
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::THREADS_PER_PAGE));
        $page = min($this->r->pageNumber(), $pages);
        $query->orderByDesc('is_sticky');
        match ($sort) {
            'erstellt' => $query->orderByDesc('created_at'),
            'antworten' => $query->orderByDesc('replies'),
            'titel' => $query->orderBy('title'),
            default => $query->orderByDesc('last_posted_at'),
        };
        $threads = $query->orderByDesc('id')->forPage($page, self::THREADS_PER_PAGE)->get();
        $url = fn (array $change): string => $this->r->url($node->slug, [...$params, ...$change]);
        $pagination = Markup::pagination($page, $pages, fn (int $p): string => $url(['seite' => $p > 1 ? $p : null]), $lang);
        $toolbar = $this->listToolbar($node, $prefixIds, $prefix, $language, $status, $sort, $url, $moderates && $threads->isNotEmpty());
        if ($threads->isEmpty()) {
            $filtered = $prefix > 0 || $language !== '' || $status !== '';

            return $pagination.sprintf('<section class="forum-section forum-themen">%s<div class="forum-leer"><span class="forum-sechseck" aria-hidden="true">%s</span><p>%s</p></div></section>', $toolbar,
                Markup::icon($filtered ? 'filter' : 'sparkles'), $this->e($filtered ? $this->t('Keine Themen für diesen Filter.') : $this->t('Noch keine Themen — eröffnen Sie das erste.')));
        }
        $rows = $this->threadRows(array_values($threads->all()), false, $moderates);

        return $pagination.sprintf('<section class="forum-section forum-themen" aria-label="%s">%s<ol class="forum-list">%s</ol></section>%s%s', $this->e($this->t('Themen')), $toolbar, $rows,
            $moderates ? $this->threadSelectionBar($node, $url(['seite' => $page > 1 ? $page : null])) : '', $pagination);
    }

    /**
     * Kopf der Themenliste (TB 47): Status als Segmente, Sortierung und Sprache als Menüs, Präfixe als Chips, für
     * Moderierende der Schalter «Auswählen» — alles als Verweise und ohne Skript.
     *
     * @param  list<int>  $prefixIds
     * @param  callable(array<string, mixed>): string  $url
     */
    private function listToolbar(Node $node, array $prefixIds, int $prefix, string $language, string $status, string $sort, callable $url, bool $selectable = false): string
    {
        $lang = $this->lang();
        $segments = '';
        foreach (['' => 'Alle', 'offen' => 'Unerledigt', 'erledigt' => 'Erledigt'] as $key => $label) {
            $segments .= sprintf('<a href="%s"%s>%s</a>', $this->e($url(['status' => $key === '' ? null : $key, 'seite' => null])), $status === $key ? ' aria-current="true"' : '', $this->e($this->t($label)));
        }
        $sorts = '';
        foreach (self::SORTS as $key => $label) {
            $sorts .= sprintf('<a href="%s"%s>%s</a>', $this->e($url(['sortierung' => $key === 'letzte' ? null : $key, 'seite' => null])), $key === $sort ? ' aria-current="true"' : '', $this->e($this->t($label)));
        }
        $tools = sprintf('<details class="forum-menue"><summary>%s %s</summary><div class="forum-menue__liste"><p class="forum-menue__titel">%s</p>%s</div></details>',
            Markup::mini('sort'), $this->e($this->t(self::SORTS[$sort])), $this->e($this->t('Sortierung')), $sorts);
        if (count($this->r->site->localeCodes()) > 1) {
            $links = sprintf('<a href="%s"%s>%s</a>', $this->e($url(['sprache' => null, 'seite' => null])), $language === '' ? ' aria-current="true"' : '', $this->e($this->t('Alle')));
            foreach ($this->r->site->localeCodes() as $code) {
                $links .= sprintf('<a href="%s"%s>%s %s</a>', $this->e($url(['sprache' => $code, 'seite' => null])), $language === $code ? ' aria-current="true"' : '', Markup::flag($code, $lang), $this->e(strtoupper($code)));
            }
            $tools .= sprintf('<details class="forum-menue forum-filter"><summary class="forum-filter__knopf%s">%s %s</summary><div class="forum-menue__liste"><p class="forum-menue__titel">%3$s</p>%s</div></details>',
                $language !== '' ? ' ist-aktiv' : '', Markup::mini('filter'), $this->e($language !== '' ? strtoupper($language) : $this->t('Sprache')), $links);
        }
        if ($selectable) {
            $tools .= sprintf('<label class="forum-schalter"><input type="checkbox" id="forum-themen-waehlen"><span class="forum-schalter__bahn" aria-hidden="true"></span>%s</label>', $this->e($this->t('Auswählen')));
        }
        $chips = '';
        $prefixes = array_filter(array_map(fn (int $id): ?Prefix => $this->prefixes()[$id] ?? null, $prefixIds));
        if ($prefixes !== []) {
            $chips = sprintf('<a href="%s"%s>%s</a>', $this->e($url(['praefix' => null, 'seite' => null])), $prefix === 0 ? ' aria-current="true"' : '', $this->e($this->t('Alle')));
            foreach ($prefixes as $p) {
                $chips .= sprintf('<a href="%s"%s>%s</a>', $this->e($url(['praefix' => $p->id, 'seite' => null])), $prefix === $p->id ? ' aria-current="true"' : '', $this->prefixBadge($p->id, false));
            }
            $chips = sprintf('<nav class="forum-praefixwahl" aria-label="%s">%s</nav>', $this->e($this->t('Präfix')), $chips);
        }

        return sprintf('<div class="forum-themen__kopf"><nav class="forum-segmente" aria-label="%s">%s</nav><div class="forum-themen__werkzeuge">%s</div></div>%s',
            $this->e($this->t('Status')), $segments, $tools, $chips);
    }

    /**
     * Zeilen einer Themenliste (TB 47): Status im Sechseck (Lava = neu), Präfix, Titel, Chips, wer und wann, Antworten
     * und Aufrufe mit Hitzebalken, letzte Antwort.
     *
     * @param  list<Thread>  $threads
     */
    private function threadRows(array $threads, bool $withForum = false, bool $selectable = false): string
    {
        $lang = $this->lang();
        $people = new People($this->r->site, [...array_map(static fn (Thread $t): ?int => $t->customer_id, $threads), ...array_map(static fn (Thread $t): ?int => $t->last_customer_id, $threads)]);
        $unread = $this->reads->unread($this->customer, $threads);
        // Reaktionen auf den Startbeitrag: die häufigste mit Gesamtzahl, wie bei WoltLab
        $reactions = $this->reactions->summary(Forum::ATTACHMENTS, array_values(array_filter(array_map(static fn (Thread $t): ?int => $t->first_post_id, $threads))));
        $multilingual = count($this->r->site->localeCodes()) > 1;
        $hottest = max(1, ...array_map(static fn (Thread $t): int => (int) $t->replies, $threads));
        $chip = fn (string $class, string $icon, string $label): string => sprintf(' <span class="forum-badge%s">%s%s</span>', $class === '' ? '' : ' forum-badge--'.$class, $icon === '' ? '' : Markup::mini($icon).' ', $this->e($this->t($label)));
        $rows = '';
        foreach ($threads as $thread) {
            if ($thread->isRedirect()) {
                $rows .= sprintf('<li class="forum-row forum-row--verweis"><span class="forum-sechseck" aria-hidden="true">%s</span><div class="forum-row__main"><h3><span class="forum-badge">%s</span> <a href="%s">%s</a></h3><p>%s</p></div></li>',
                    Markup::icon('move'), $this->e($this->t('Verschoben')), $this->e($this->r->url('thema/'.$thread->pathSegment())), $this->e($thread->title), $this->e($people->name($thread->customer_id, $lang)));

                continue;
            }
            $isNew = in_array($thread->id, $unread, true);
            $url = $this->r->url('thema/'.$thread->pathSegment().($isNew ? '/ungelesen' : ''));
            $flags = ($isNew ? $chip('neu', '', 'Neu') : '')
                .($thread->is_sticky ? $chip('dunkel', 'pin', 'Angeheftet') : '')
                .($thread->is_announcement && ! $withForum ? $chip('', 'megaphone', 'Ankündigung') : '')
                .($thread->is_done ? $chip('erledigt', 'check', 'Erledigt') : '')
                .($thread->is_closed ? $chip('', 'lock', 'Geschlossen') : '')
                .($thread->is_disabled ? $chip('wartet', 'clock', 'Wartet auf die Moderation') : '')
                .($thread->is_featured ? $chip('empfohlen', 'star', 'Empfohlen') : '');
            [$hex, $icon, $title] = match (true) {
                $isNew => ['forum-sechseck--neu', $thread->is_sticky ? 'pin' : 'chat', 'Neue Beiträge'],
                $thread->is_disabled => ['forum-sechseck--wartet', 'clock', 'Wartet auf die Moderation'],
                $thread->is_done => ['forum-sechseck--ok', 'check', 'Erledigt'],
                $thread->is_sticky => ['forum-sechseck--dunkel', 'pin', 'Angeheftet'],
                $thread->is_closed => ['', 'lock', 'Geschlossen'],
                default => ['', 'chat', 'Thema'],
            };
            $forumName = '';
            if ($withForum) {
                $node = $this->forum->nodes($this->r->site)->get($thread->node_id);
                $forumName = $node === null ? '' : sprintf('<span>%s <a href="%s">%s</a></span>', Markup::mini('folder'), $this->e($this->r->url($node->slug)), $this->e($node->titleIn($lang)));
            }
            $counts = $reactions[$thread->first_post_id] ?? [];
            arsort($counts);
            $top = array_key_first($counts);
            $signals = ($top === null ? '' : sprintf('<span class="forum-row__reaktion" title="%s">%s <small>×%d</small></span>', $this->e(Reactions::label((string) $top, $lang)), Reactions::TYPES[$top] ?? '', array_sum($counts)))
                .($multilingual && $thread->locale !== $lang ? Markup::flag($thread->locale, $lang) : '');
            $heat = (int) round(100 * min(1, ((int) $thread->replies) / $hottest));
            $rows .= sprintf('<li class="forum-row forum-row--thema%s">%s<span class="forum-sechseck %s" title="%s" aria-hidden="true">%s</span><div class="forum-row__main"><h3>%s<a href="%s">%s</a></h3><p class="forum-row__unter">%s<span>%s</span><span>%s</span>%s</p></div>'
                .'<div class="forum-row__zahlen"><span><b>%s</b> %s · <b>%s</b> %s %s</span><span class="forum-hitze" aria-hidden="true"><i style="width:%d%%"></i></span></div><div class="forum-row__last">%s<span><b>%s</b>%s</span></div></li>',
                ($isNew ? ' forum-row--neu' : '').($thread->is_disabled ? ' forum-row--wartet' : ''), $selectable ? '<span class="forum-row__waehlen">'.$this->threadCheckbox($thread).'</span>' : '',
                $hex, $this->e($this->t($title)), Markup::icon($icon), $this->prefixBadge($thread->prefix_id), $this->e($url), $this->e($thread->title), ltrim($flags),
                $this->e($people->name($thread->customer_id, $lang)), Markup::time($thread->created_at, $lang), $forumName,
                Markup::number($thread->replies, $lang), $this->e(trans_choice('Antwort|Antworten', $thread->replies, [], $lang)), Markup::compact((int) $thread->views, $lang),
                $this->e(trans_choice('Aufruf|Aufrufe', (int) $thread->views, [], $lang)), $signals, max(4, $heat),
                $people->avatar($thread->last_customer_id, $lang, 'klein'), $this->e($people->name($thread->last_customer_id, $lang)), Markup::time($thread->last_posted_at, $lang));
        }

        return $rows;
    }

    /**
     * Abonnieren (Glocke, gedrückt = abonniert) und Ignorieren als Knöpfe — für ein Thema oder ein Forum. Ist es
     * ignoriert, kommt «Wieder zeigen» an die Stelle der Glocke.
     *
     * @return array{0: string, 1: string} Glocke für die Leiste, Ignorieren für das Menü
     */
    private function subscribeToggle(string $kind, int $id, ?string $mode, string $page): array
    {
        $action = route('basalto.forum.subscribe', absolute: false);
        $lang = $this->lang();
        if ($mode === Subscriptions::IGNORE) {
            return [Activity::button($action, ['art' => $kind, 'id' => $id, 'modus' => 'none'], $this->t('Wieder zeigen'), $page, $lang, true, 'eye'), ''];
        }

        return [$mode === Subscriptions::WATCH
            ? Activity::button($action, ['art' => $kind, 'id' => $id, 'modus' => 'none'], $this->t('Abonniert'), $page, $lang, true, 'bell')
            : Activity::button($action, ['art' => $kind, 'id' => $id, 'modus' => 'watch'], $this->t('Abonnieren'), $page, $lang, false, 'bell'),
            Activity::button($action, ['art' => $kind, 'id' => $id, 'modus' => 'ignore'], $this->t('Ignorieren'), $page, $lang, false, 'eye-off')];
    }

    /** @return array<int, Prefix> */
    private function prefixes(): array
    {
        return $this->prefixes ??= Prefix::query()->withoutGlobalScopes()->where('site_id', $this->r->site->id)->orderBy('position')->orderBy('id')->get()->keyBy('id')->all();
    }

    private function prefixBadge(?int $id, bool $space = true): string
    {
        $prefix = $id === null ? null : ($this->prefixes()[$id] ?? null);

        return $prefix === null ? '' : sprintf('<span class="forum-praefix forum-praefix--%s">%s</span>%s', $this->e($prefix->color), $this->e($prefix->labelIn($this->lang())), $space ? ' ' : '');
    }

    // ── Neues Thema ─────────────────────────────────────────────

    /** «+ Neues Thema» auf der Übersicht: zuerst das Forum wählen (nur, wo die Person eröffnen darf). */
    private function chooseBoard(): MountResult
    {
        $title = $this->t('Neues Thema');
        $crumbs = [['label' => $title, 'href' => $this->r->url('neu')]];
        if ($this->customer === null) {
            return $this->page($this->loginNote(), $title, $crumbs, null, true, true);
        }
        $site = $this->r->site;
        $lang = $this->lang();
        $items = '';
        foreach ($this->forum->nodes($site) as $node) {
            if ($node->type === NodeType::Board && $this->forum->canStart($site, $this->customer, $node)) {
                $path = implode(' › ', array_map(static fn (Node $n): string => $n->titleIn($lang), $this->forum->ancestors($site, $node)));
                $items .= sprintf('<li class="forum-row"><span class="forum-sechseck forum-sechseck--forum" aria-hidden="true">%s</span><div class="forum-row__main"><h3><a href="%s">%s</a></h3><p>%s</p></div></li>',
                    Markup::icon((string) $node->setting('icon', 'chat')), $this->e($this->r->url($node->slug.'/neu')), $this->e($node->titleIn($lang)), $this->e($path !== '' ? $path : $node->descriptionIn($lang)));
            }
        }
        $html = $this->bar().sprintf('<p class="forum-lead">%s</p>', $this->e($this->t('In welchem Forum möchten Sie das Thema eröffnen?')))
            .($items === '' ? sprintf('<p class="forum-note">%s</p>', $this->e($this->t('Sie dürfen derzeit in keinem Forum Themen eröffnen.'))) : '<section class="forum-section"><ul class="forum-list">'.$items.'</ul></section>');

        return $this->page($html, $title, $crumbs, null, true, true);
    }

    private function newThread(string $slug): MountResult
    {
        $site = $this->r->site;
        $node = $this->forum->bySlug($site, $slug);
        if ($node === null || $node->type !== NodeType::Board) {
            return MountResult::notFound();
        }
        if (! $this->forum->can($site, $this->customer, Forum::VIEW, $node)) {
            return $this->denied();
        }
        $this->areasOf($node);
        $crumbs = [...$this->crumbs($node, true), ['label' => $this->t('Neues Thema'), 'href' => $this->r->url($node->slug.'/neu')]];
        if ($this->customer === null) {
            return $this->page($this->loginNote(), $this->t('Neues Thema'), $crumbs, null, true, true);
        }
        if (! $this->forum->canStart($site, $this->customer, $node)) {
            return $this->denied();
        }
        if ($this->profiles->of($this->customer) === null) {
            return $this->page($this->profileFirst(), $this->t('Neues Thema'), $crumbs, null, true, true);
        }
        $request = $this->r->request;
        $errors = Html::errors($request, 'forum');
        $old = Html::old($request);
        $lang = $this->lang();
        $form = $this->formStart(route('basalto.forum.threads.store', absolute: false), $this->t('Neues Thema in «:forum»', ['forum' => $node->titleIn($lang)]), $this->r->url($node->slug.'/neu'), $errors)
            .sprintf('<input type="hidden" name="node" value="%d">', $node->id);
        $form .= $this->prefixSelect($node, is_numeric($old['praefix'] ?? null) ? (int) $old['praefix'] : null, $errors);
        $form .= Html::field('forum-titel', 'title', $this->e($this->t('Titel')).Html::required(),
            sprintf('<input id="forum-titel" name="title" type="text" required maxlength="120" value="%s" data-insel="aehnlich" data-insel-url="%s" data-insel-basis="%s" data-insel-text="%s"{aria}>',
                $this->e(is_string($old['title'] ?? null) ? $old['title'] : ''), $this->e(route('basalto.forum.similar', absolute: false)), $this->e($this->r->base),
                $this->e($this->t('Ähnliche Themen — vielleicht ist Ihre Frage schon beantwortet:'))),
            $this->t('Kurz und genau — so finden andere Ihr Thema.'), $errors)
            .'<div id="forum-aehnlich" class="forum-aehnlich" aria-live="polite" hidden></div>';
        $form .= ThreadFields::inputs($node, $old, $errors, $lang);
        $form .= $this->tagsInput(is_string($old['tags'] ?? null) ? $old['tags'] : '', $errors);
        $form .= Html::field('forum-text', 'body', $this->e($this->t('Beitrag')).Html::required(),
            Islands::editor('forum-text', 'body', is_string($old['body'] ?? null) ? $old['body'] : '', $lang, 10, true, $this->editorOptions('neu-'.$node->id)), null, $errors);
        $form .= $this->fileInput($errors).$this->pollFields($old, $errors).$this->officialCheckbox($node, $old);
        $form .= sprintf('<p class="form-actions"><button type="submit" class="forum-button">%s %s</button></p></form>', Markup::mini('send'), $this->e($this->t('Thema eröffnen')));

        return $this->page($this->bar().$form, $this->t('Neues Thema'), $crumbs, null, true, true);
    }

    /**
     * Eine Umfrage zum Thema (FO-12) — aufklappbar, leer bleibt sie weg.
     *
     * @param  array<string, mixed>  $old
     */
    private function pollFields(array $old, MessageBag $errors): string
    {
        $poll = is_array($old['umfrage'] ?? null) ? $old['umfrage'] : [];
        $value = static fn (string $key): string => is_string($poll[$key] ?? null) ? $poll[$key] : '';
        $open = $value('frage') !== '' || $errors->has('umfrage.optionen') || $errors->has('umfrage.ende');
        $fields = Html::field('forum-umfrage-frage', 'umfrage.frage', $this->e($this->t('Frage')),
            sprintf('<input id="forum-umfrage-frage" name="umfrage[frage]" type="text" maxlength="200" value="%s"{aria}>', $this->e($value('frage'))), null, $errors);
        $fields .= Html::field('forum-umfrage-optionen', 'umfrage.optionen', $this->e($this->t('Antworten (eine je Zeile)')),
            sprintf('<textarea id="forum-umfrage-optionen" name="umfrage[optionen]" rows="4"{aria}>%s</textarea>', $this->e($value('optionen'))), $this->t('2 bis 20 Antworten.'), $errors);
        $fields .= Html::field('forum-umfrage-max', 'umfrage.max', $this->e($this->t('Wie viele darf man wählen?')),
            sprintf('<input id="forum-umfrage-max" name="umfrage[max]" type="number" min="1" max="20" value="%s"{aria}>', $this->e($value('max') !== '' ? $value('max') : '1')), null, $errors);
        $fields .= Html::field('forum-umfrage-ende', 'umfrage.ende', $this->e($this->t('Ende (freiwillig)')),
            sprintf('<input id="forum-umfrage-ende" name="umfrage[ende]" type="date" value="%s"{aria}>', $this->e($value('ende'))), null, $errors);
        $fields .= sprintf('<label class="community-check"><input type="checkbox" name="umfrage[nach_stimme]" value="1"%s> %s</label>', $value('nach_stimme') !== '' || $poll === [] ? ' checked' : '',
            $this->e($this->t('Ergebnis erst nach der eigenen Stimme zeigen')));

        return sprintf('<details class="forum-zusatz" data-insel-reiter="umfrage"%s><summary>%s</summary>%s</details>', $open ? ' open' : '', $this->e($this->t('Umfrage hinzufügen')), $fields);
    }

    /** TB 41: Schlagwörter mit Komma getrennt. */
    private function tagsInput(string $value, MessageBag $errors): string
    {
        return Html::field('forum-tags', 'tags', $this->e($this->t('Schlagwörter')),
            sprintf('<input id="forum-tags" name="tags" type="text" maxlength="200" value="%s"{aria}>', $this->e($value)), $this->t('Höchstens :max, mit Komma getrennt — etwa «Theme, Lava».', ['max' => Tags::MAX]), $errors);
    }

    /** @param  array<string, mixed>  $old */
    private function officialCheckbox(Node $node, array $old): string
    {
        if (! $this->forum->canMarkOfficial($this->r->site, $this->customer, $node)) {
            return '';
        }

        return sprintf('<label class="community-check" data-insel-reiter="einstellungen"><input type="checkbox" name="offiziell" value="1"%s> %s</label>', ($old['offiziell'] ?? null) ? ' checked' : '',
            $this->e($this->t('Als offiziellen Beitrag des Teams kennzeichnen')));
    }

    /** Kopf eines Schreibformulars: mehrteilig (Anhänge ohne Skript), mit Token, Seite, Sprache, Einhängeseite, Fehlern und Honigtopf. */
    private function formStart(string $action, string $heading, string $page, MessageBag $errors, string $id = ''): string
    {
        return sprintf('<form class="forum-form form"%s method="post" action="%s" enctype="multipart/form-data" novalidate><h2>%s</h2>', $id === '' ? '' : ' id="'.$this->e($id).'"', $this->e($action), $this->e($heading))
            .Html::hidden($page, $this->lang(), true).sprintf('<input type="hidden" name="_basis" value="%s">', $this->e($this->r->base))
            .Html::errorSummary($errors, $this->lang()).Html::honeypot();
    }

    /**
     * Optionen der Editor-Insel: Anhänge (wenn erlaubt), Erwähnungen, Zitate, Entwurf.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array{upload?: string, names?: string, quote?: string, doc?: array<string, mixed>|null, draft?: string}
     */
    private function editorOptions(?string $draft, ?array $doc = null): array
    {
        $options = ['names' => route('basalto.community.names', absolute: false), 'quote' => '/forum/posts/{id}/zitat', 'preview' => route('basalto.community.preview', absolute: false)];
        if ($this->customer !== null && app(VisitorAccess::class)->allows($this->r->site, $this->customer, Attachments::PERMISSION)) {
            $options['upload'] = route('basalto.community.attachments.store', absolute: false);
            $options['rules'] = $this->attachmentRules();
        }
        if ($draft !== null) {
            $options['draft'] = 'forum:'.$this->r->site->id.':'.$draft;
        }
        if ($doc !== null) {
            $options['doc'] = $doc;
        }

        return $options;
    }

    /** TB 49: die Regeln der Anhänge als ein Satz für den Reiter «Anhänge». */
    private function attachmentRules(): string
    {
        $attachments = app(Attachments::class);
        $site = $this->r->site;
        $free = $this->customer === null ? 0 : max(0, $attachments->quotaBytes($site) - $attachments->usedBytes($this->customer));

        return $this->t('Höchstens :files Dateien je Beitrag, je :mb MB · erlaubt: :types · noch :free MB frei', ['files' => $attachments->maxFiles($site), 'mb' => intdiv($attachments->maxBytes($site), 1024 * 1024),
            'types' => implode(', ', $attachments->types($site)), 'free' => number_format($free / 1024 / 1024, 1, '.', "'")]);
    }

    /** Dateien ohne Skript; die Insel ersetzt das Feld durch Hochladen mit Vorschau. */
    private function fileInput(MessageBag $errors): string
    {
        if ($this->customer === null || ! app(VisitorAccess::class)->allows($this->r->site, $this->customer, Attachments::PERMISSION)) {
            return '';
        }
        $types = implode(', ', app(Attachments::class)->types($this->r->site));

        return Html::field('forum-anhaenge', 'anhaenge', $this->e($this->t('Anhänge')),
            '<input id="forum-anhaenge" name="anhaenge[]" type="file" multiple data-insel-dateien{aria}>', $this->t('Erlaubt: :types, je Datei bis :mb MB.', ['types' => $types, 'mb' => intdiv(app(Attachments::class)->maxBytes($this->r->site), 1048576)]), $errors);
    }

    private function prefixSelect(Node $node, ?int $current, MessageBag $errors): string
    {
        $ids = array_map('intval', (array) $node->setting('prefixes', []));
        $options = '';
        foreach ($ids as $id) {
            $prefix = $this->prefixes()[$id] ?? null;
            if ($prefix !== null) {
                $options .= sprintf('<option value="%d"%s>%s</option>', $prefix->id, $current === $prefix->id ? ' selected' : '', $this->e($prefix->labelIn($this->lang())));
            }
        }
        if ($options === '') {
            return '';
        }
        $required = (bool) $node->setting('prefix_required', false);

        return Html::field('forum-praefix', 'praefix', $this->e($this->t('Präfix')).($required ? Html::required() : ''),
            sprintf('<select id="forum-praefix" name="praefix"%s{aria}><option value="">%s</option>%s</select>', $required ? ' required' : '', $this->e($required ? $this->t('Bitte wählen') : $this->t('ohne')), $options), null, $errors);
    }

    // ── Thema ───────────────────────────────────────────────────

    private function thread(string $segment): MountResult
    {
        $site = $this->r->site;
        if (preg_match('/^(\d+)(?:-[a-z0-9-]*)?$/', $segment, $m) !== 1) {
            return MountResult::notFound();
        }
        $thread = Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->where('site_id', $site->id)->find((int) $m[1]);
        // Verschoben oder zusammengeführt (TB 39): weiter zum Ziel, solange es den Verweis gibt
        if ($thread !== null && $thread->isRedirect()) {
            $target = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->find($thread->moved_to_id);

            return $target === null ? MountResult::notFound() : MountResult::redirect($this->r->url('thema/'.$target->pathSegment()));
        }
        $node = $thread === null ? null : $this->forum->nodes($site)->get($thread->node_id);
        if ($thread === null || $node === null || $thread->trashed() || ($thread->is_disabled && ! $this->moderates($node))) {
            return MountResult::notFound();
        }
        $this->areasOf($node);
        $moderates = $this->moderates($node);
        if ($segment !== $thread->pathSegment()) {
            return MountResult::redirect($this->r->url('thema/'.$thread->pathSegment(), ['seite' => $this->r->query('seite')]));
        }
        if (! $this->forum->can($site, $this->customer, Forum::READ, $node)) {
            return $this->denied();
        }
        $lang = $this->lang();
        // TB 47: ab wann Beiträge für die Person neu sind — gemessen, bevor das Thema als gelesen gilt
        $since = $this->customer === null ? null : $this->reads->readSince($this->customer, [$thread])[$thread->id];
        $query = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->when(! $moderates, static fn ($q) => $q->where('is_disabled', false));
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::POSTS_PER_PAGE));
        $page = min($this->r->pageNumber(), $pages);
        $posts = $query->orderBy('id')->forPage($page, self::POSTS_PER_PAGE)->get();
        $visible = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_disabled', false);
        $fresh = $since === null ? [] : array_values(array_map('intval', (clone $visible)->where('created_at', '>', $since)->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', '!=', $this->customer->id))->orderBy('id')->limit(500)->pluck('id')->all()));
        $participants = array_map('intval', (clone $visible)->whereNotNull('customer_id')->selectRaw('customer_id, count(*) as anzahl')->groupBy('customer_id')->orderByDesc('anzahl')->limit(8)
            ->pluck('anzahl', 'customer_id')->all());
        $participantCount = (clone $visible)->whereNotNull('customer_id')->distinct()->count('customer_id');
        $people = new People($site, [...$posts->pluck('customer_id')->all(), ...$posts->pluck('edited_by')->all(), ...array_keys($participants), $thread->customer_id]);
        Thread::query()->withoutGlobalScope(SiteScope::class)->whereKey($thread->id)->increment('views');
        if ($this->customer !== null) {
            $this->reads->markThread($this->customer, $thread);
        }
        $context = $this->postContext(array_values($posts->all()));
        $context['reactions'] = $this->reactions->summary(Forum::ATTACHMENTS, $context['onPage']);
        $context['mine'] = $this->reactions->mine($this->customer, Forum::ATTACHMENTS, $context['onPage']);
        $context['reports'] = $moderates ? app(Reports::class)->openFor(Moderation::REPORT_TYPE, $context['onPage']) : [];
        $context['fresh'] = $fresh;
        $official = array_values(array_map('intval', Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_official', true)->where('is_disabled', false)->orderBy('id')->pluck('id')->all()));
        $this->structuredData($thread, array_values($posts->all()), $people);

        $poll = $thread->poll()->withoutGlobalScopes()->first();
        $pollHtml = $poll === null ? '' : Activity::poll($poll, $this->customer?->id, $this->customer !== null && $this->forum->can($site, $this->customer, Forum::REPLY, $node),
            $this->r->url('thema/'.$thread->pathSegment()), $lang, Html::errors($this->r->request, 'forum'));
        $items = '';
        $offset = ($page - 1) * self::POSTS_PER_PAGE;
        $number = $offset;
        foreach ($posts as $post) {
            $number++;
            $fields = $post->id === $thread->first_post_id ? ThreadFields::display($node, $thread->form_values, $lang).$pollHtml : '';
            $items .= $this->postItem($post, $number, $people, $thread, $node, $context, $fields);
        }
        $main = sprintf('<ol class="forum-posts" id="forum-beitraege" aria-label="%s">%s</ol>', $this->e($this->t('Beiträge')), $items)
            .($moderates ? $this->postSelectionBar($thread, $node) : '')
            .Markup::pagination($page, $pages, fn (int $p): string => $this->r->url('thema/'.$thread->pathSegment(), ['seite' => $p > 1 ? $p : null]), $lang)
            .$this->liveThread($thread, $page === $pages, (int) ($posts->last()->id ?? 0), $pages)
            .($page === $pages && ! $thread->is_closed ? $this->typingTrace($thread) : '')
            .$this->replyArea($thread, $node);
        $aside = ($moderates ? $this->threadMenu($thread, $node) : '').$this->compass(array_values($posts->all()), $thread, $people, $fresh, $page, $pages, $total, $offset)
            .$this->participantsCard($participants, $participantCount, $people);
        $html = $this->bar().$this->threadHero($thread, $node, $people, $participants, $participantCount, $fresh, $official, $context['onPage'], $moderates)
            .sprintf('<div class="forum-thema"><div class="forum-thema__haupt">%s</div><aside class="forum-thema__seite" aria-label="%s">%s</aside></div>', $main, $this->e($this->t('Rund um das Thema')), $aside);
        $crumbs = [...$this->crumbs($node, true), ['label' => $thread->title, 'href' => $this->r->url('thema/'.$thread->pathSegment())]];
        $first = $posts->first();

        return $this->page($html, $thread->title, $crumbs, $first === null ? null : mb_substr(RichText::toText($first->body), 0, 160), image: $this->shareImage($thread));
    }

    /** TB 51: das erste Bild des Startbeitrags als Vorschaubild beim Teilen (`og:image`), als volle Adresse. */
    private function shareImage(Thread $thread): ?string
    {
        $first = $thread->first_post_id === null ? null : Post::query()->withoutGlobalScope(SiteScope::class)->find($thread->first_post_id);
        $id = $first === null ? null : (CommunityRichText::images($first->body)[0] ?? null);
        $attachment = $id === null ? null : CommunityAttachment::query()->withoutGlobalScopes()->where('site_id', $this->r->site->id)->find($id);

        return $attachment === null ? null : $this->r->request->getSchemeAndHttpHost().$attachment->url();
    }

    /**
     * Kopf des Themas (TB 47): Status, Präfix und Schlagwörter als Chips, wer es wann wo eröffnet hat, Kennzahlen mit
     * «neu für Sie» und den Beteiligten, Sprünge zur Lösung und zu offiziellen Beiträgen, die Aktionen.
     *
     * @param  array<int, int>  $participants
     * @param  list<int>  $fresh
     * @param  list<int>  $official
     * @param  list<int>  $onPage
     */
    private function threadHero(Thread $thread, Node $node, People $people, array $participants, int $participantCount, array $fresh, array $official, array $onPage, bool $moderates): string
    {
        $lang = $this->lang();
        $site = $this->r->site;
        $page = $this->r->url('thema/'.$thread->pathSegment());
        $chip = fn (string $class, string $icon, string $label): string => sprintf('<span class="forum-badge%s">%s %s</span>', $class === '' ? '' : ' forum-badge--'.$class, Markup::mini($icon), $this->e($this->t($label)));
        $tags = Tags::of([$thread->id])[$thread->id] ?? [];
        $chips = $this->prefixBadge($thread->prefix_id, false)
            .($thread->is_sticky ? $chip('dunkel', 'pin', 'Angeheftet') : '')
            .($thread->is_announcement ? $chip('', 'megaphone', 'Ankündigung') : '')
            .($thread->is_done ? $chip('erledigt', 'check-circle', 'Erledigt') : '')
            .($thread->is_closed ? $chip('', 'lock', 'Geschlossen') : '')
            .($thread->is_disabled ? $chip('wartet', 'clock', 'Wartet auf die Moderation') : '')
            .($thread->is_featured ? $chip('empfohlen', 'star', 'Empfohlen') : '')
            .($tags === [] ? '' : '<span class="forum-schlagwoerter">'.$this->tagLinks($tags).'</span>');
        $meta = sprintf('<p class="forum-held__meta">%s<span>%s</span><span>%s %s</span><span>%s <a href="%s">%s</a></span></p>', $people->avatar($thread->customer_id, $lang, 'klein'),
            $people->link($thread->customer_id, $lang, fn (string $p): string => $this->r->url($p)), Markup::mini('clock'), Markup::time($thread->created_at, $lang),
            Markup::mini('folder'), $this->e($this->r->url($node->slug)), $this->e($node->titleIn($lang)));
        $target = fn (int $id): string => in_array($id, $onPage, true) ? '#beitrag-'.$id : $this->r->url('beitrag/'.$id);
        $stack = Markup::stack(array_map(fn (int $id): string => $people->avatar($id, $lang, 'klein'), array_slice(array_keys($participants), 0, 4)), max(0, $participantCount - 4));
        $figures = sprintf('<div><dt>%s</dt><dd>%s</dd></div><div><dt>%s</dt><dd>%s</dd></div><div class="forum-kennzahl--stapel"><dt>%s</dt><dd>%s%d</dd></div>',
            $this->e(trans_choice('Antwort|Antworten', (int) $thread->replies, [], $lang)), Markup::number((int) $thread->replies, $lang),
            $this->e(trans_choice('Aufruf|Aufrufe', (int) $thread->views, [], $lang)), Markup::compact((int) $thread->views, $lang), $this->e($this->t('beteiligt')), $stack, $participantCount)
            .($fresh === [] ? '' : sprintf('<div class="forum-kennzahl--neu"><dt>%s</dt><dd><a href="%s">%d</a></dd></div>', $this->e($this->t('neu für Sie')), $this->e($target($fresh[0])), count($fresh)));
        $jumps = ($thread->best_post_id !== null ? sprintf('<a class="forum-sprung" href="%s">%s %s</a>', $this->e($target($thread->best_post_id)), Markup::mini('check-circle'), $this->e($this->t('Zur hilfreichsten Antwort'))) : '')
            .($official !== [] ? sprintf('<a class="forum-sprung forum-sprung--team" href="%s">%s %s</a>', $this->e($target($official[0])), Markup::mini('megaphone'),
                $this->e(trans_choice(':count offizieller Beitrag des Teams|:count offizielle Beiträge des Teams', count($official), ['count' => count($official)], $lang))) : '');
        $actions = '';
        if ($this->customer === null || $this->forum->canReply($site, $this->customer, $thread, $node)) {
            $actions .= sprintf('<a class="forum-button" href="#antworten" data-forum-antworten>%s %s</a>', Markup::mini('reply'), $this->e($this->t('Antworten')));
        }
        $more = '';
        if ($this->customer !== null) {
            [$watch, $more] = $this->subscribeToggle('thema', $thread->id, $this->subscriptions->modeOf($this->customer, 'forum_thread', $thread->id), $page);
            $actions .= $watch;
        }
        $link = $this->r->request->getSchemeAndHttpHost().$page;
        // TB 51: Teilen ohne Datenübertragung vor dem Klick — Netzwerke als Links, Link kopieren, Teilen des Systems
        $actions .= $this->menu($this->t('Teilen'), Share::html($link, $thread->title, $lang).sprintf('<label>%s <input type="text" readonly value="%s"></label>', $this->e($this->t('Adresse des Themas')), $this->e($link)), 'share')
            .($moderates ? sprintf('<a class="forum-aktion forum-aktion--icon forum-held__mod" href="#forum-moderation" title="%1$s">%2$s<span class="visually-hidden">%1$s</span></a>', $this->e($this->t('Moderation')), Markup::icon('shield')) : '')
            .$this->menu($this->t('Weitere Aktionen'), $more);

        return sprintf('<section class="forum-held forum-held--thema" aria-label="%s"><div class="forum-held__text">%s%s</div><div class="forum-held__leiste"><div class="forum-held__zahlen"><dl class="forum-kennzahlen">%s</dl>%s</div><div class="forum-held__knoepfe">%s</div></div></section>',
            $this->e($this->t('Über dieses Thema')), $chips === '' ? '' : '<div class="forum-held__chips">'.$chips.'</div>', $meta, $figures, $jumps === '' ? '' : '<div class="forum-held__chips">'.$jumps.'</div>', $actions);
    }

    /**
     * Der Kompass am Rand (TB 47): die Beiträge der Seite als Waben in der Farbe der Person, die geschrieben hat — so
     * zeigt er, wer das Gespräch trägt —, hervorgehoben neu, Lösung, wartet, Team; dazu Sprünge und die Tastenkürzel.
     * Mit Skript zeigt er, wo man gerade liest, und nimmt live nachgeladene Beiträge auf.
     *
     * @param  list<Post>  $posts
     * @param  list<int>  $fresh
     */
    private function compass(array $posts, Thread $thread, People $people, array $fresh, int $page, int $pages, int $total, int $offset): string
    {
        $lang = $this->lang();
        $cells = '';
        $kinds = [];
        $number = $offset;
        foreach ($posts as $post) {
            $number++;
            $kind = match (true) {
                $post->is_disabled => 'ist-wartend',
                $thread->best_post_id === $post->id => 'ist-beste',
                in_array($post->id, $fresh, true) => 'ist-neu',
                $post->is_official => 'ist-offiziell',
                default => '',
            };
            $kinds[$kind] = true;
            $name = $people->name($post->customer_id, $lang);
            $cells .= sprintf('<li><a href="#beitrag-%d" class="ton-%d%s" title="%s"><span>%d</span></a></li>', $post->id, Markup::tone($people->profile($post->customer_id), $name),
                $kind === '' ? '' : ' '.$kind, $this->e($this->t('Beitrag :number', ['number' => $number]).' · '.$name), $number);
        }
        $legend = '';
        foreach (['ist-neu' => 'neu', 'ist-beste' => 'Lösung', 'ist-wartend' => 'wartet', 'ist-offiziell' => 'Team'] as $kind => $label) {
            $legend .= isset($kinds[$kind]) ? sprintf('<span><i class="%s"></i>%s</span>', $kind, $this->e($this->t($label))) : '';
        }
        $onPage = array_map(static fn (Post $p): int => $p->id, $posts);
        $target = fn (int $id): string => in_array($id, $onPage, true) ? '#beitrag-'.$id : $this->r->url('beitrag/'.$id);
        $jumps = ($fresh !== [] ? sprintf('<a href="%s">%s %s <b>%d</b></a>', $this->e($target($fresh[0])), Markup::mini('arrow-down'), $this->e($this->t('Zum ersten neuen')), count($fresh)) : '')
            .($thread->best_post_id !== null ? sprintf('<a href="%s">%s %s</a>', $this->e($target($thread->best_post_id)), Markup::mini('check-circle'), $this->e($this->t('Zur hilfreichsten Antwort'))) : '')
            .sprintf('<a href="#antworten">%s %s</a>', Markup::mini('reply'), $this->e($this->t('Zum Antworten')));

        return sprintf('<section class="forum-karte forum-kompass" aria-labelledby="forum-kompass-titel" data-kompass data-kompass-titel="%s"><h2 id="forum-kompass-titel">%s%s<small>%s</small></h2><ol class="forum-waben" aria-label="%s">%s</ol>%s'
            .'<div class="forum-spruenge">%s</div><p class="forum-tasten">%s <kbd>J</kbd> <kbd>K</kbd> · %s <kbd>R</kbd></p></section>',
            $this->e($this->t('Beitrag :number')), Markup::icon('compass'), $this->e($this->t('Kompass')),
            $this->e($pages > 1 ? $this->t('Seite :page von :pages', ['page' => $page, 'pages' => $pages]) : trans_choice(':count Beitrag|:count Beiträge', $total, ['count' => $total], $lang)),
            $this->e($this->t('Beiträge dieser Seite')), $cells, $legend === '' ? '' : '<p class="forum-legende" aria-hidden="true">'.$legend.'</p>', $jumps,
            $this->e($this->t('Blättern')), $this->e($this->t('Antworten')));
    }

    /** @param  array<int, int>  $participants  Konto → Zahl der Beiträge */
    private function participantsCard(array $participants, int $count, People $people): string
    {
        if ($participants === []) {
            return '';
        }
        $lang = $this->lang();
        $items = '';
        foreach ($participants as $id => $posts) {
            $items .= sprintf('<li>%s%s<small>%s</small></li>', $people->avatar($id, $lang, 'klein'), $people->link($id, $lang, fn (string $p): string => $this->r->url($p)),
                $this->e(trans_choice(':count Beitrag|:count Beiträge', $posts, ['count' => $posts], $lang)));
        }

        return sprintf('<section class="forum-karte" aria-labelledby="forum-beteiligt-titel"><h2 id="forum-beteiligt-titel">%s%s<small>%d</small></h2><ul class="forum-personen">%s</ul></section>',
            Markup::icon('users'), $this->e($this->t('Beteiligt')), $count, $items);
    }

    /**
     * CR-008: Leiste «n neue Beiträge — anzeigen» vor dem Antwortformular — sichtbar beim Lesen wie beim Schreiben. Auf
     * der letzten Seite holt sie die neuen Beiträge in die Seite, auf früheren führt sie zur letzten.
     */
    private function liveThread(Thread $thread, bool $lastPage, int $lastPostId, int $pages): string
    {
        $live = app(Live::class);
        $key = ForumLive::thread($thread->id);
        if (! $lastPage) {
            return sprintf('<div class="forum-live" role="status" hidden%s><span>%s</span> <a class="forum-button" href="%s">%s</a></div>', $live->attributes($this->r->site->id, $key, 'hinweis'),
                $this->e($this->t('Neue Beiträge in diesem Thema')), $this->e($this->r->url('thema/'.$thread->pathSegment(), ['seite' => $pages])), $this->e($this->t('Zur letzten Seite')));
        }

        return sprintf('<div class="forum-live" role="status" hidden%s><span data-live-text></span> <button type="button" class="forum-button" data-live-zeigen>%s</button></div>',
            $live->attributes($this->r->site->id, $key, 'nachladen', ['quelle' => $this->r->url('thema/'.$thread->id.'/neu'), 'nach' => $lastPostId, 'ziel' => '#forum-beitraege',
                'eins' => $this->t('1 neuer Beitrag'), 'mehr' => $this->t(':count neue Beiträge')]), $this->e($this->t('Anzeigen')));
    }

    /** TB 49: «Anna schreibt …» am Ende der Lava-Linie — für alle, die das Thema lesen, auch Gäste. */
    private function typingTrace(Thread $thread): string
    {
        return sprintf('<div class="forum-schreibt"><span class="forum-schreibt__spur" aria-hidden="true">%s</span>%s</div>', Markup::icon('chat'),
            Islands::typingIndicator($this->r->site->id, ForumLive::typing($thread->id), $this->lang(), $this->customer === null ? null : $this->profiles->of($this->customer)?->id));
    }

    /** CR-008: verborgener Hinweis, der erscheint, sobald sich unter dem Schlüssel etwas ändert — mit «Aktualisieren». */
    private function liveNotice(string $key, string $text): string
    {
        return sprintf('<div class="forum-live" role="status" hidden%s><span>%s</span> <a class="forum-button" href="%s">%s</a></div>', app(Live::class)->attributes($this->r->site->id, $key, 'hinweis'),
            $this->e($text), $this->e($this->r->request->getRequestUri()), $this->e($this->t('Aktualisieren')));
    }

    /**
     * CR-008: die Beiträge eines Themas nach `?nach=<Beitrag>` als Fragment für die Live-Aktualisierung — mit denselben
     * Prüfungen und demselben Markup wie die Themenseite. Mehr als eine Seite voll: nur Anzahl und Adresse der letzten Seite.
     */
    private function threadUpdates(string $segment): MountResult
    {
        $site = $this->r->site;
        $json = static fn (array $data): MountResult => MountResult::raw((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json');
        $thread = ctype_digit($segment) ? Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->find((int) $segment) : null;
        $node = $thread === null ? null : $this->forum->nodes($site)->get($thread->node_id);
        if ($thread === null || $node === null || $thread->isRedirect() || ($thread->is_disabled && ! $this->moderates($node))) {
            return MountResult::notFound();
        }
        if (! $this->forum->can($site, $this->customer, Forum::READ, $node)) {
            return $this->denied();
        }
        $after = max(0, (int) $this->r->query('nach'));
        $moderates = $this->moderates($node);
        $query = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->when(! $moderates, static fn ($q) => $q->where('is_disabled', false));
        $count = (clone $query)->where('id', '>', $after)->count();
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::POSTS_PER_PAGE));
        $last = $this->r->url('thema/'.$thread->pathSegment(), ['seite' => $pages > 1 ? $pages : null]);
        if ($count === 0 || $count > self::POSTS_PER_PAGE) {
            return $json(['anzahl' => $count, 'html' => null, 'url' => $last, 'nach' => $after]);
        }
        $posts = (clone $query)->where('id', '>', $after)->orderBy('id')->get();
        $people = new People($site, [...$posts->pluck('customer_id')->all(), ...$posts->pluck('edited_by')->all()]);
        $context = $this->postContext(array_values($posts->all()));
        $context['reactions'] = $this->reactions->summary(Forum::ATTACHMENTS, $context['onPage']);
        $context['mine'] = $this->reactions->mine($this->customer, Forum::ATTACHMENTS, $context['onPage']);
        $context['reports'] = $moderates ? app(Reports::class)->openFor(Moderation::REPORT_TYPE, $context['onPage']) : [];
        // TB 47: was nachkommt, ist neu — ausser den eigenen Beiträgen
        $context['fresh'] = array_values(array_map('intval', $posts->filter(fn (Post $p): bool => $p->customer_id === null || $p->customer_id !== $this->customer?->id)->pluck('id')->all()));
        $number = (clone $query)->where('id', '<=', $after)->count();
        $html = '';
        foreach ($posts as $post) {
            $html .= $this->postItem($post, ++$number, $people, $thread, $node, $context, '');
        }
        if ($this->customer !== null) {
            $this->reads->markThread($this->customer, $thread);
        }

        return $json(['anzahl' => $count, 'html' => $html, 'url' => $last, 'nach' => (int) $posts->last()?->id]);
    }

    /**
     * Was die Beiträge einer Seite zum Darstellen brauchen: Namen der Erwähnten, Anhänge je Beitrag, Sprungziele.
     *
     * @param  list<Post>  $posts
     * @return array{names: array<int, string>, attachments: array<int, list<CommunityAttachment>>, onPage: list<int>, reactions?: array<int, array<string, int>>, mine?: array<int, string>, reports?: array<int, int>}
     */
    private function postContext(array $posts): array
    {
        $mentioned = [];
        foreach ($posts as $post) {
            array_push($mentioned, ...CommunityRichText::mentions($post->body));
        }
        $names = $mentioned === [] ? [] : CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $this->r->site->id)->whereIn('id', array_unique($mentioned))->pluck('name', 'id')->all();
        $attachments = [];
        $ids = array_map(static fn (Post $p): int => $p->id, $posts);
        if ($ids !== []) {
            foreach (CommunityAttachment::query()->withoutGlobalScopes()->where('subject_type', Forum::ATTACHMENTS)->whereIn('subject_id', $ids)->orderBy('id')->get() as $attachment) {
                $attachments[$attachment->subject_id][] = $attachment;
            }
        }

        return ['names' => array_map('strval', $names), 'attachments' => $attachments, 'onPage' => $ids];
    }

    /** @param  array{names: array<int, string>, attachments: array<int, list<CommunityAttachment>>, onPage: list<int>, reactions?: array<int, array<string, int>>, mine?: array<int, string>, reports?: array<int, int>, fresh?: list<int>}  $context */
    private function postItem(Post $post, int $number, People $people, Thread $thread, Node $node, array $context, string $fields = ''): string
    {
        $lang = $this->lang();
        $site = $this->r->site;
        $badge = $people->badge($post->customer_id);
        $profile = $people->profile($post->customer_id);
        // TB 40: Name als Verweis aufs Profil, Rang und Trophäen; TB 47: als Chips im Kopf, das Bild an der Linie
        $rank = $people->rank($post->customer_id);
        $trophies = $people->trophies($post->customer_id);
        $online = $profile?->last_activity_at !== null && $profile->last_activity_at->gt(now()->subMinutes(Widgets::ONLINE_MINUTES));
        $isNew = in_array($post->id, $context['fresh'] ?? [], true);
        $isBest = $thread->best_post_id === $post->id;
        $moderates = $this->moderates($node);
        $chips = ($badge === null ? '' : sprintf('<span class="forum-badge%s">%s</span>', $people->isTeam($post->customer_id) ? ' forum-badge--team' : '', $this->e($this->t($badge))))
            .($rank === null ? '' : Markup::rank($rank))
            .($trophies === [] ? '' : sprintf('<span class="forum-badge" title="%s">%s %d</span>', $this->e(implode(', ', array_map(static fn ($t): string => $t->icon.' '.$t->title, $trophies))), Markup::mini('award'), count($trophies)))
            .($profile === null ? '' : sprintf('<span class="forum-post__beitraege">%s</span>', $this->e(trans_choice(':count Beitrag|:count Beiträge', $profile->posts, ['count' => Markup::digits($profile->posts, $lang)], $lang))));
        $head = sprintf('<header class="forum-post__head" id="beitrag-%1$d-kopf">%2$s<span class="forum-post__name">%3$s</span>%4$s%5$s<span class="forum-post__zeit">%6$s %7$s <a class="forum-post__nummer" href="#beitrag-%1$d">#%8$d</a></span></header>',
            $post->id, $moderates ? $this->postCheckbox($post) : '', $people->link($post->customer_id, $lang, fn (string $p): string => $this->r->url($p)), $chips,
            ($online ? '<span class="visually-hidden">'.$this->e($this->t('Online')).'</span>' : '').($isNew ? '<span class="visually-hidden">'.$this->e($this->t('Neu')).'</span>' : ''),
            Markup::mini('clock'), Markup::time($post->created_at, $lang), $number);
        $signature = $profile !== null && is_string($profile->signature) && $profile->signature !== ''
            ? sprintf('<div class="forum-post__signature">%s</div>', $this->e($profile->signature)) : '';
        $postUrl = fn (int $id): string => in_array($id, $context['onPage'], true) ? '#beitrag-'.$id : $this->r->url('beitrag/'.$id);
        $text = CommunityRichText::toHtml($post->body, $lang, $context['names'], $postUrl);
        $files = $this->attachmentList($context['attachments'][$post->id] ?? [], CommunityRichText::images($post->body));
        $edited = '';
        $history = $post->edit_count > 0 && $this->forum->canSeeHistory($site, $this->customer, $post, $node);
        if ($post->edit_count > 0 && $post->edited_at !== null) {
            $note = $this->t('Bearbeitet :zeit', ['zeit' => Markup::time($post->edited_at, $lang)]);
            $edited = sprintf('<p class="forum-post__bearbeitet">%s %s%s%s</p>', Markup::mini('pencil'), $note, $post->edit_reason !== null ? ' · '.$this->e($post->edit_reason) : '',
                $history ? sprintf(' · <a href="%s">%s</a>', $this->e($this->r->url('beitrag/'.$post->id.'/verlauf')), $this->e($this->t('Verlauf'))) : '');
        }
        // TB 52: gesperrtes Bearbeiten — sehen, wer geschrieben hat, und die Moderation
        if ($post->is_edit_locked && ($this->moderates($node) || ($this->customer !== null && $post->customer_id === $this->customer->id))) {
            $edited .= sprintf('<p class="forum-post__bearbeitet">%s %s</p>', Markup::mini('lock'), $this->e($this->t('Bearbeiten gesperrt (Moderation)')));
        }
        $bands = ($isBest ? sprintf('<p class="forum-post__band forum-post__band--beste">%s %s</p>', Markup::mini('check-circle'), $this->e($this->t('Hilfreichste Antwort'))) : '')
            .($post->is_official ? sprintf('<p class="forum-post__band forum-post__band--offiziell">%s %s</p>', Markup::mini('megaphone'), $this->e($this->t('Offizieller Beitrag'))) : '');
        // Werkzeuge: Antworten, Zitieren, Merken sichtbar; Bearbeiten, Lösung, Verlauf, Melden und Moderation im Menü
        $tools = '';
        $menu = '';
        if ($this->customer !== null && $this->forum->canReply($site, $this->customer, $thread, $node)) {
            $tools .= sprintf('<a class="forum-aktion forum-aktion--leise" href="#antworten" data-forum-antworten title="%2$s">%1$s<span>%2$s</span></a>', Markup::mini('reply'), $this->e($this->t('Antworten')))
                .sprintf('<button type="button" class="forum-aktion forum-aktion--leise" data-forum-zitieren="%1$d" title="%3$s" hidden>%2$s<span data-label>%3$s</span></button>', $post->id, Markup::mini('quote'), $this->e($this->t('Zitieren')))
                .sprintf('<button type="button" class="forum-aktion forum-aktion--leise" data-forum-merken="%1$d" title="%3$s" hidden>%2$s<span data-label>%3$s</span></button>', $post->id, Markup::mini('bookmark'), $this->e($this->t('Merken')));
        }
        if ($this->forum->canEdit($site, $this->customer, $post, $thread, $node)) {
            $menu .= sprintf('<a href="%s">%s %s</a>', $this->e($this->r->url('beitrag/'.$post->id.'/bearbeiten')), Markup::mini('pencil'), $this->e($this->t('Bearbeiten')));
        }
        if ($post->id !== $thread->first_post_id && $this->forum->canMarkBest($site, $this->customer, $thread, $node)) {
            $menu .= Activity::button(route('basalto.forum.best', ['thread' => $thread->id], false), $isBest ? [] : ['beitrag' => $post->id],
                $isBest ? $this->t('Nicht mehr hilfreichste Antwort') : $this->t('Hilfreichste Antwort'), $this->r->url('thema/'.$thread->pathSegment()), $lang, $isBest, 'check-circle');
        }
        if ($history) {
            $menu .= sprintf('<a href="%s">%s %s</a>', $this->e($this->r->url('beitrag/'.$post->id.'/verlauf')), Markup::mini('history'), $this->e($this->t('Verlauf')));
        }
        $reactions = Activity::reactions($post, $context['reactions'][$post->id] ?? [], $context['mine'][$post->id] ?? null,
            $this->customer !== null && $post->customer_id !== $this->customer->id && ! $post->is_disabled, $this->r->url('thema/'.$thread->pathSegment()), $lang);
        // TB 39: melden für alle Angemeldeten, Moderation am Beitrag
        $menu .= $post->is_disabled ? '' : $this->reportForm($post, $thread);
        $moderation = $moderates ? $this->postModeration($post, $thread, $context['reports'][$post->id] ?? 0) : ['strip' => '', 'menu' => ''];
        $menu .= $moderation['menu'];
        $tools .= $this->menu($this->t('Weitere Aktionen'), $menu);
        $footer = $reactions === '' && $tools === '' ? '' : sprintf('<footer class="forum-post__fuss">%s<div class="forum-post__werkzeuge">%s</div></footer>', $reactions, $tools);
        $classes = ($post->is_official ? ' forum-post--offiziell' : '').($isBest ? ' forum-post--beste' : '').($post->is_disabled ? ' forum-post--wartet' : '').($isNew ? ' forum-post--neu' : '');

        $html = sprintf('<article class="forum-post%s" id="beitrag-%d" aria-labelledby="beitrag-%2$d-kopf">%s%s%s<div class="forum-post__body">%s<div class="forum-post__text">%s</div>%s%s%s</div>%s</article>',
            $classes, $post->id, $bands, $moderation['strip'], $head, $fields, $text, $files, $edited, $signature, $footer);
        // TB 40 (FO-18): Beiträge blockierter Personen sind zugeklappt
        if ($this->customer !== null && in_array($post->customer_id, $this->blocked(), true)) {
            $html = sprintf('<details class="forum-blockiert"><summary>%s</summary>%s</details>', $this->e($this->t('Beitrag von :name ausgeblendet — Sie haben die Person blockiert.', ['name' => $people->name($post->customer_id, $lang)])), $html);
        }
        $rail = sprintf('<div class="forum-eintrag__spur" aria-hidden="true"><span class="forum-portrait">%s%s</span>%s</div>', $people->avatar($post->customer_id, $lang, 'gross'),
            $online ? '<span class="forum-portrait__online"></span>' : '', $isNew ? '<span class="forum-eintrag__neu">'.$this->e($this->t('Neu')).'</span>' : '');

        return sprintf('<li class="forum-eintrag%s" data-nummer="%d">%s%s</li>', $isNew ? ' ist-neu' : '', $number, $rail, $html);
    }

    /**
     * Anhänge unter dem Beitrag — ohne die Bilder, die schon im Text stehen.
     *
     * @param  list<CommunityAttachment>  $attachments
     * @param  list<int>  $inText
     */
    private function attachmentList(array $attachments, array $inText): string
    {
        $items = '';
        foreach ($attachments as $attachment) {
            if (in_array($attachment->id, $inText, true)) {
                continue;
            }
            $items .= $attachment->isImage()
                ? sprintf('<li><a href="%1$s"><img src="%1$s" alt="%2$s" loading="lazy" width="120"></a></li>', $this->e($attachment->url()), $this->e($attachment->name))
                : sprintf('<li><a href="%s" download>%s</a> <span>(%s)</span></li>', $this->e($attachment->url()), $this->e($attachment->name), $this->e(Number::fileSize($attachment->size, 1)));
        }

        return $items === '' ? '' : sprintf('<div class="forum-anhaenge"><p>%s</p><ul>%s</ul></div>', $this->e($this->t('Anhänge')), $items);
    }

    /** Antworten am Ende des Themas (TB 47): das eigene Sechseck an der Linie, daneben der Editor. */
    private function replyArea(Thread $thread, Node $node): string
    {
        $site = $this->r->site;
        $lang = $this->lang();
        if ($this->customer === null) {
            return sprintf('<section class="forum-antwort--gast" id="antworten"><p>%s</p><a class="forum-button" href="%s">%s %s</a></section>', $this->e($this->t('Möchten Sie antworten?')),
                $this->e($this->r->url('konto')), Markup::mini('login'), $this->e($this->t('Anmelden oder registrieren')));
        }
        if (app(Sanctions::class)->isMuted($this->customer)) {
            return '';
        }
        if (! $this->forum->canReply($site, $this->customer, $thread, $node)) {
            return sprintf('<p class="forum-note" id="antworten">%s %s</p>', Markup::mini('lock'), $this->e($thread->is_closed || $node->isClosed() ? $this->t('Dieses Thema ist geschlossen.') : $this->t('Sie dürfen in diesem Forum nicht antworten.')));
        }
        if ($this->profiles->of($this->customer) === null) {
            return $this->profileFirst();
        }
        $request = $this->r->request;
        $errors = Html::errors($request, 'forum');
        $old = Html::old($request);
        $form = $this->formStart(route('basalto.forum.replies.store', ['thread' => $thread->id], false), $this->t('Antworten'), $this->r->url('thema/'.$thread->pathSegment()), $errors, 'antworten');
        $form .= Html::field('forum-antwort', 'body', $this->e($this->t('Ihre Antwort')).Html::required(),
            Islands::editor('forum-antwort', 'body', is_string($old['body'] ?? null) ? $old['body'] : '', $lang, 6, true, ['typing' => Typing::key(ForumLive::typing($thread->id))] + $this->editorOptions('antwort-'.$thread->id)), null, $errors)
            .Islands::typingIndicator($site->id, ForumLive::typing($thread->id), $lang, $this->profiles->of($this->customer)->id, true);
        $form .= $this->fileInput($errors).$this->officialCheckbox($node, $old).sprintf('<p class="form-actions"><button type="submit" class="forum-button">%s %s</button></p></form>', Markup::mini('send'), $this->e($this->t('Antwort senden')));
        $name = $this->profiles->displayName($this->customer);

        return sprintf('<section class="forum-antwort"><div class="forum-eintrag__spur" aria-hidden="true">%s</div>%s</section>', Markup::avatar($this->profiles->of($this->customer), $name, 'gross'), $form);
    }

    // ── Beitrag: Sprung, Bearbeiten, Verlauf ────────────────────

    /** @return array{0: Post, 1: Thread, 2: Node}|null */
    private function findPost(string $segment): ?array
    {
        if (preg_match('/^\d+$/', $segment) !== 1) {
            return null;
        }
        $site = $this->r->site;
        $post = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->find((int) $segment);
        $thread = $post === null ? null : Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->find($post->thread_id);
        $node = $thread === null ? null : $this->forum->nodes($site)->get($thread->node_id);

        return $post === null || $thread === null || $node === null ? null : [$post, $thread, $node];
    }

    /** `/community/beitrag/12` → zur richtigen Seite des Themas, mit Sprungmarke (für Zitate und Benachrichtigungen). */
    private function postRedirect(string $segment): MountResult
    {
        $found = $this->findPost($segment);
        if ($found === null || ! $this->forum->can($this->r->site, $this->customer, Forum::READ, $found[2])) {
            return MountResult::notFound();
        }
        [$post, $thread] = $found;
        $count = Post::query()->withoutGlobalScope(SiteScope::class)->where('thread_id', $thread->id)->where('is_disabled', false)->where('id', '<=', $post->id)->count();
        $page = (int) ceil($count / self::POSTS_PER_PAGE);

        return MountResult::redirect($this->r->url('thema/'.$thread->pathSegment(), ['seite' => $page > 1 ? $page : null]).'#beitrag-'.$post->id);
    }

    private function editPost(string $segment): MountResult
    {
        $found = $this->findPost($segment);
        if ($found === null) {
            return MountResult::notFound();
        }
        [$post, $thread, $node] = $found;
        $site = $this->r->site;
        $crumbs = [...$this->crumbs($node, true), ['label' => $thread->title, 'href' => $this->r->url('thema/'.$thread->pathSegment())],
            ['label' => $this->t('Bearbeiten'), 'href' => $this->r->url('beitrag/'.$post->id.'/bearbeiten')]];
        if (! $this->forum->canEdit($site, $this->customer, $post, $thread, $node)) {
            return $this->customer === null ? $this->page($this->loginNote(), $this->t('Beitrag bearbeiten'), $crumbs, null, true, true) : $this->denied();
        }
        $lang = $this->lang();
        $errors = Html::errors($this->r->request, 'forum');
        $old = Html::old($this->r->request);
        $isFirst = $thread->first_post_id === $post->id;
        $form = $this->formStart(route('basalto.forum.posts.update', ['post' => $post->id], false), $this->t('Beitrag bearbeiten'), $this->r->url('beitrag/'.$post->id.'/bearbeiten'), $errors);
        if ($isFirst) {
            $form .= $this->prefixSelect($node, array_key_exists('praefix', $old) ? (is_numeric($old['praefix']) ? (int) $old['praefix'] : null) : $thread->prefix_id, $errors);
            $form .= Html::field('forum-titel', 'title', $this->e($this->t('Titel')).Html::required(),
                sprintf('<input id="forum-titel" name="title" type="text" required maxlength="120" value="%s"{aria}>', $this->e(is_string($old['title'] ?? null) ? $old['title'] : $thread->title)), null, $errors);
            $form .= ThreadFields::inputs($node, $old !== [] ? $old : ['feld' => $thread->form_values ?? []], $errors, $lang);
            $form .= $this->tagsInput(is_string($old['tags'] ?? null) ? $old['tags'] : implode(', ', array_column(Tags::of([$thread->id])[$thread->id] ?? [], 'name')), $errors);
        }
        $form .= Html::field('forum-text', 'body', $this->e($this->t('Beitrag')).Html::required(),
            // CR-013: Bearbeiten speichert eine neue Fassung erst mit dem Knopf — bis dahin bleibt ein Entwurf im Browser
            Islands::editor('forum-text', 'body', is_string($old['body'] ?? null) ? $old['body'] : RichText::toText($post->body), $lang, 10, true, $this->editorOptions('bearbeiten-'.$post->id, $old === [] ? $post->body : null)), null, $errors);
        $form .= Html::field('forum-grund', 'reason', $this->e($this->t('Grund der Änderung')),
            sprintf('<input id="forum-grund" name="reason" type="text" maxlength="200" value="%s"{aria}>', $this->e(is_string($old['reason'] ?? null) ? $old['reason'] : '')), $this->t('Freiwillig — erscheint unter dem Beitrag.'), $errors);
        $form .= $this->fileInput($errors).sprintf('<p class="form-actions"><button type="submit" class="forum-button">%s</button> <a href="%s">%s</a></p></form>',
            $this->e($this->t('Änderung speichern')), $this->e($this->r->url('thema/'.$thread->pathSegment()).'#beitrag-'.$post->id), $this->e($this->t('Abbrechen')));

        return $this->page($this->bar().$form, $this->t('Beitrag bearbeiten'), $crumbs, null, true, true);
    }

    private function history(string $segment): MountResult
    {
        $found = $this->findPost($segment);
        if ($found === null) {
            return MountResult::notFound();
        }
        [$post, $thread, $node] = $found;
        if (! $this->forum->canSeeHistory($this->r->site, $this->customer, $post, $node)) {
            return $this->denied();
        }
        $lang = $this->lang();
        $revisions = Revision::query()->withoutGlobalScopes()->where('post_id', $post->id)->orderByDesc('id')->get();
        $people = new People($this->r->site, [...$revisions->pluck('customer_id')->all(), $post->customer_id, $post->edited_by]);
        $items = sprintf('<li class="forum-fassung"><p class="forum-fassung__kopf"><b>%s</b> · %s · %s</p><div class="forum-post__text">%s</div></li>',
            $this->e($this->t('Aktuell')), Markup::time($post->edited_at ?? $post->created_at, $lang), $this->e($people->name($post->edited_by ?? $post->customer_id, $lang)), CommunityRichText::toHtml($post->body, $lang));
        foreach ($revisions as $revision) {
            $items .= sprintf('<li class="forum-fassung"><p class="forum-fassung__kopf"><b>%s</b> · %s%s</p>%s<div class="forum-post__text">%s</div></li>',
                $this->e($this->t('Vor der Änderung von :name', ['name' => $people->name($revision->customer_id, $lang)])), Markup::time($revision->created_at, $lang),
                $revision->reason !== null ? ' · '.$this->e($revision->reason) : '', $revision->title !== null ? '<p class="forum-fassung__titel">'.$this->e($this->t('Titel: :title', ['title' => $revision->title])).'</p>' : '',
                CommunityRichText::toHtml($revision->body, $lang));
        }
        $crumbs = [...$this->crumbs($node, true), ['label' => $thread->title, 'href' => $this->r->url('thema/'.$thread->pathSegment())],
            ['label' => $this->t('Verlauf'), 'href' => $this->r->url('beitrag/'.$post->id.'/verlauf')]];

        return $this->page($this->bar().sprintf('<ol class="forum-fassungen">%s</ol><p><a class="forum-aktion" href="%s">%s %s</a></p>', $items, $this->e($this->r->url('beitrag/'.$post->id)), Markup::mini('reply'), $this->e($this->t('Zurück zum Beitrag'))),
            $this->t('Verlauf des Beitrags'), $crumbs, null, true, true);
    }

    // ── Listen, Benachrichtigungen ──────────────────────────────

    /**
     * Ungelesen (nur angemeldet), Aktuell (die neuesten Themen aller lesbaren Foren) und Abonniert.
     */
    private function listing(string $kind): MountResult
    {
        $site = $this->r->site;
        $lang = $this->lang();
        $titles = ['ungelesen' => $this->t('Ungelesene Themen'), 'aktuell' => $this->t('Aktuelle Themen'), 'abonniert' => $this->t('Abonnierte Themen')];
        $crumbs = [['label' => $titles[$kind], 'href' => $this->r->url($kind)]];
        if ($kind !== 'aktuell' && $this->customer === null) {
            return $this->page($this->loginNote(), $titles[$kind], $crumbs, null, true, true);
        }
        $readable = $this->visible($this->forum->nodes($site)->values())->filter(fn (Node $n): bool => $this->forum->can($site, $this->customer, Forum::READ, $n))->map(static fn (Node $n): int => $n->id)->all();
        $query = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereIn('node_id', $readable)->whereNull('moved_to_id')
            ->whereNotIn('id', $this->subscriptions->ignored($this->customer, 'forum_thread'));
        if ($kind === 'abonniert') {
            $query->whereIn('id', $this->subscriptions->watched($this->customer, 'forum_thread'));
        }
        if ($kind === 'ungelesen') {
            $query->where('last_posted_at', '>', now()->subDays(ReadTracker::HORIZON_DAYS));
        }
        $threads = $query->orderByDesc('last_posted_at')->limit($kind === 'ungelesen' ? 200 : 50)->get()->all();
        if ($kind === 'ungelesen') {
            $unread = $this->reads->unread($this->customer, array_values($threads));
            $threads = array_slice(array_values(array_filter($threads, static fn (Thread $t): bool => in_array($t->id, $unread, true))), 0, 50);
        }
        $actions = $kind === 'ungelesen' && $threads !== [] ? Activity::button(route('basalto.forum.read', absolute: false), [], $this->t('Alles als gelesen markieren'), $this->r->url('ungelesen'), $lang, false, 'check') : '';
        $empty = ['ungelesen' => $this->t('Alles gelesen — nichts Neues seit Ihrem letzten Besuch.'), 'aktuell' => $this->t('Noch keine Themen.'), 'abonniert' => $this->t('Sie haben noch kein Thema abonniert.')];
        $icons = ['ungelesen' => 'check-circle', 'aktuell' => 'sparkles', 'abonniert' => 'bell'];
        $list = $threads === [] ? sprintf('<section class="forum-section"><div class="forum-leer"><span class="forum-sechseck%s" aria-hidden="true">%s</span><p>%s</p></div></section>', $kind === 'ungelesen' ? ' forum-sechseck--ok' : '', Markup::icon($icons[$kind]), $this->e($empty[$kind]))
            : sprintf('<section class="forum-section" aria-label="%s"><ol class="forum-list">%s</ol></section>', $this->e($titles[$kind]), $this->threadRows(array_values($threads), true));

        return $this->page($this->bar($actions).$list, $titles[$kind], $crumbs, null, $kind !== 'aktuell', $kind !== 'aktuell');
    }

    /** `/community/thema/12-titel/ungelesen` → zum ersten ungelesenen Beitrag (sonst zum letzten). */
    private function firstUnread(string $segment): MountResult
    {
        $site = $this->r->site;
        $thread = preg_match('/^(\d+)/', $segment, $m) === 1 ? Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->find((int) $m[1]) : null;
        if ($thread === null) {
            return MountResult::notFound();
        }
        $post = $this->customer === null ? null : $this->reads->firstUnread($this->customer, $thread);

        return MountResult::redirect($this->r->url('beitrag/'.($post->id ?? $thread->last_post_id ?? $thread->first_post_id)));
    }

    private function notifications(): MountResult
    {
        $crumbs = [['label' => $this->t('Benachrichtigungen'), 'href' => $this->r->url('benachrichtigungen')]];
        if ($this->customer === null) {
            return $this->page($this->loginNote(), $this->t('Benachrichtigungen'), $crumbs, null, true, true);
        }
        $lang = $this->lang();
        $center = app(CommunityNotifications::class);
        $items = '';
        foreach ($center->latest($this->customer) as $row) {
            $body = CommunityNotifications::body($row, $lang);
            $new = $row->getAttribute('read_at') === null;
            $items .= sprintf('<li class="forum-nachricht%s"><span class="forum-sechseck forum-sechseck--klein%s" aria-hidden="true">%s</span><a href="%s">%s</a>%s<span>%s</span></li>', $new ? ' forum-nachricht--neu' : '',
                $new ? ' forum-sechseck--neu' : '', Markup::icon('bell'), $this->e($this->r->url('benachrichtigungen/'.$row->id)), $this->e(CommunityNotifications::title($row, $lang)), $body === '' ? '' : '<p>'.$this->e($body).'</p>', Markup::time($row->getAttribute('created_at'), $lang));
        }
        $actions = $center->unread($this->customer) > 0 ? Activity::button(route('basalto.forum.notifications.read', absolute: false), [], $this->t('Alle als gelesen markieren'), $this->r->url('benachrichtigungen'), $lang, false, 'check') : '';
        $list = $items === '' ? sprintf('<section class="forum-section"><div class="forum-leer"><span class="forum-sechseck" aria-hidden="true">%s</span><p>%s</p></div></section>', Markup::icon('bell'), $this->e($this->t('Noch keine Benachrichtigungen. Abonnieren Sie Themen, um von neuen Antworten zu erfahren.')))
            : sprintf('<section class="forum-section"><ol class="forum-nachrichten">%s</ol></section>', $items);
        $settings = sprintf('<p class="forum-note">%s %s <a href="%s">%s</a></p>', Markup::mini('mail'), $this->e($this->t('Ob und wann E-Mails kommen, stellen Sie im Profil ein.')), $this->e($this->r->url('konto')), $this->e($this->t('Zum Profil')));
        // CR-008 (LV-10): Desktop-Benachrichtigungen — erscheint nur, wenn der Browser sie kann (Insel live.ts)
        $settings .= sprintf('<p class="forum-note" hidden data-live-desktop data-an="%s" data-aus="%s">%s <button type="button" class="forum-aktion"></button></p>',
            $this->e($this->t('Einschalten')), $this->e($this->t('Ausschalten')), $this->e($this->t('Desktop-Benachrichtigungen, wenn das Forum im Hintergrund offen ist:')));

        return $this->page($this->bar($actions).$list.$settings, $this->t('Benachrichtigungen'), $crumbs, null, true, true);
    }

    /** Eine Benachrichtigung öffnen: als gelesen markieren und weiter zum Ziel. */
    private function followNotification(string $id): MountResult
    {
        $row = $this->customer === null || preg_match('/^[0-9a-f-]{36}$/', $id) !== 1 ? null : app(CommunityNotifications::class)->find($this->customer, $id);
        if ($row === null) {
            return MountResult::notFound();
        }
        $row->markAsRead();

        return MountResult::redirect(CommunityNotifications::url($row) ?? $this->r->url('benachrichtigungen'));
    }

    // ── Konto ───────────────────────────────────────────────────

    private function account(): MountResult
    {
        $crumbs = [['label' => $this->t('Konto'), 'href' => $this->r->url('konto')]];
        $path = $this->r->url('konto');
        if ($this->customer === null) {
            $html = sprintf('<p class="forum-lead">%s</p>', $this->e($this->t('Melden Sie sich mit Ihrer E-Mail-Adresse an — wir schicken Ihnen einen Code, ein Passwort brauchen Sie nicht. Ist die Adresse neu, entsteht Ihr Konto dabei.')))
                .app(LoginForm::class)->render($this->r->request, $this->lang(), $path);

            return $this->page($html, $this->t('Anmelden'), $crumbs, null, true, true);
        }
        $own = $this->profiles->of($this->customer);
        $html = ($own === null ? '' : sprintf('<p class="forum-note"><a href="%s">%s</a></p>', $this->e($this->profileUrl($own)), $this->e($this->t('Ihr öffentliches Profil ansehen'))))
            .app(ProfileForm::class)->render($this->r->request, $this->customer, $path, $this->lang())
            .app(LoginForm::class)->signedIn($this->customer, $this->lang(), $path);

        return $this->page($html, $this->t('Ihr Profil'), $crumbs, null, true, true);
    }

    // ── Hilfen ──────────────────────────────────────────────────

    /** @return list<array{label: string, href: string}> Brotkrumen bis zum Forum */
    private function crumbs(Node $node, bool $includeSelf = true): array
    {
        $lang = $this->lang();
        $out = [];
        foreach ([...$this->forum->ancestors($this->r->site, $node), ...($includeSelf ? [$node] : [])] as $item) {
            $out[] = ['label' => $item->titleIn($lang), 'href' => $item->type === NodeType::Category ? $this->r->url('', []).'#forum-kategorie-'.$item->id : $this->r->url($item->slug)];
        }

        return $out;
    }

    private function loginNote(): string
    {
        return sprintf('<p class="forum-note">%s <a href="%s">%s</a></p>', $this->e($this->t('Zum Schreiben brauchen Sie ein Konto.')), $this->e($this->r->url('konto')), $this->e($this->t('Anmelden oder registrieren')));
    }

    private function profileFirst(): string
    {
        return sprintf('<p class="forum-note">%s <a href="%s">%s</a></p>', $this->e($this->t('Bevor Sie schreiben: Wählen Sie einen Anzeigenamen.')), $this->e($this->r->url('konto')), $this->e($this->t('Zum Profil')));
    }

    private function denied(): MountResult
    {
        $html = '<div class="forum"><p class="forum-note">'.$this->e($this->customer === null ? $this->t('Dieser Bereich ist nur für angemeldete Mitglieder mit Berechtigung.') : $this->t('Für diesen Bereich fehlt Ihnen die Berechtigung.')).'</p>'
            .($this->customer === null ? $this->loginNote() : '').'</div>';

        return MountResult::denied($html.Styles::tag(), $this->t('Kein Zugriff'));
    }
}
