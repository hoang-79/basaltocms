<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Community\CommunityNotifications;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityTerms;
use Basalto\Core\Community\Conversations;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Community\Recognition;
use Basalto\Core\Community\Relations;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\FrontendAccounts\Hub\AccountArea;
use Basalto\Core\FrontendAccounts\Hub\AccountHub;
use Basalto\Core\FrontendAccounts\Hub\AccountPage;
use Basalto\Core\FrontendAccounts\Hub\AreaAction;
use Basalto\Core\FrontendAccounts\Hub\AreaEvent;
use Basalto\Core\FrontendAccounts\Hub\AreaPitch;
use Basalto\Core\FrontendAccounts\Hub\AreaSummary;
use Basalto\Core\FrontendAccounts\Hub\AreaTodo;
use Basalto\Core\FrontendAccounts\Hub\Icons;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\ReadTracker;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bereich «Community» im Kundenkonto (TB 48) — die Verbindung zum Forum: das eigene Profil mit Rang und Zahlen,
 * ungelesene Benachrichtigungen und Nachrichten (auch unter «Als Nächstes»), die eigenen Themen und die letzten
 * Beiträge unter «Zuletzt». Ohne Profil lädt der Bereich ein, einen Anzeigenamen zu wählen.
 */
final class CommunityArea implements AccountArea
{
    private string $base = '';

    private ?CommunityProfile $profile = null;

    private int $unread = 0;

    private int $messages = 0;

    /** @var Collection<int, DatabaseNotification> */
    private Collection $latest;

    /** @var list<Thread> */
    private array $threads = [];

    /** @var list<int> */
    private array $unreadThreads = [];

    public function __construct(private readonly ReadTracker $reads)
    {
        $this->latest = collect();
    }

    public function key(): string
    {
        return 'community';
    }

    public function position(): int
    {
        return 50;
    }

    public function pitch(AccountPage $page): ?AreaPitch
    {
        return $this->baseOf($page) === null ? null : new AreaPitch('chat', $page->t('Community'), $page->t('Ihr Profil, Benachrichtigungen, Nachrichten und Ihre Themen im Forum.'));
    }

    public function summary(AccountPage $page): ?AreaSummary
    {
        $customer = $page->customer;
        $base = $this->baseOf($page);
        if ($customer === null || $base === null) {
            return null;
        }
        $this->base = $base;
        $site = $page->site;
        $lang = $page->lang;
        $this->profile = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('customer_id', $customer->id)->first();
        $center = app(CommunityNotifications::class);
        $this->unread = $center->unread($customer);
        $this->messages = count(app(Conversations::class)->unreadIds($customer));
        $this->latest = $center->latest($customer, 3);
        $this->threads = array_values(Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('customer_id', $customer->id)->where('is_disabled', false)
            ->whereNull('moved_to_id')->orderByDesc('last_posted_at')->limit(4)->get()->all());
        $this->unreadThreads = $this->reads->unread($customer, $this->threads);
        $todos = [];
        if ($this->unread > 0) {
            $todos[] = new AreaTodo('bell', trans_choice(':count neue Benachrichtigung in der Community|:count neue Benachrichtigungen in der Community', $this->unread, ['count' => $this->unread], $lang),
                new AreaAction($page->t('Ansehen'), $base.'/benachrichtigungen', 'bell'), 'lava');
        }
        if ($this->messages > 0) {
            $todos[] = new AreaTodo('mail', trans_choice(':count ungelesene Nachricht|:count ungelesene Nachrichten', $this->messages, ['count' => $this->messages], $lang),
                new AreaAction($page->t('Lesen'), $base.'/konversationen', 'mail'), 'lava');
        }
        $terms = app(CommunityTerms::class);
        if ($terms->required($site) && ! $terms->accepted($site, $customer)) {
            $todos[] = new AreaTodo('check', $page->t('Stimmen Sie den Bedingungen der Community zu — vorher lässt sie sich nicht nutzen.'), new AreaAction($page->t('Zustimmen'), $base, 'check'), 'lava');
        }
        if ($this->profile === null) {
            $todos[] = new AreaTodo('user', $page->t('Wählen Sie einen Anzeigenamen — dann können Sie im Forum mitschreiben.'), new AreaAction($page->t('Profil anlegen'), $base.'/konto', 'user'));
        }
        $events = [];
        $posts = Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('customer_id', $customer->id)->where('is_disabled', false)->orderByDesc('id')->limit(4)->get();
        $titles = Thread::query()->withoutGlobalScope(SiteScope::class)->whereIn('id', $posts->pluck('thread_id'))->pluck('title', 'id');
        foreach ($posts as $post) {
            if (isset($titles[$post->thread_id]) && $post->created_at !== null) {
                $events[] = new AreaEvent($post->created_at, 'chat', $page->t('Beitrag in «:thread»', ['thread' => (string) $titles[$post->thread_id]]), $base.'/beitrag/'.$post->id);
            }
        }
        $news = $this->unread + $this->messages;
        $caption = match (true) {
            $news > 0 => trans_choice(':count neu|:count neu', $news, ['count' => $news], $lang),
            $this->profile !== null => trans_choice(':count Beitrag|:count Beiträge', $this->profile->posts, ['count' => $this->profile->posts], $lang),
            default => $page->t('noch kein Profil'),
        };

        return new AreaSummary($page->t('Community'), 'chat', $news, $caption, $news > 0 ? 'lava' : '', $todos, $events, [new AreaAction($page->t('Forum'), $base, 'chat')],
            $this->profile === null ? [] : [new AreaAction($page->t('Profil'), $base.'/mitglied/'.$this->profile->pathSegment(), 'user')]);
    }

    public function render(AccountPage $page, AreaSummary $summary): string
    {
        $lang = $page->lang;
        $base = $this->base;
        if ($this->profile === null) {
            $html = sprintf('<div class="konto-leer"><span class="konto-sechseck konto-sechseck--lava" aria-hidden="true">%s</span><p>%s</p><a class="konto-knopf konto-knopf--lava" href="%s">%s %s</a></div>',
                Icons::svg('user'), Markup::e($page->t('Noch kein Profil in der Community. Mit einem Anzeigenamen können Sie Fragen stellen, antworten und Themen abonnieren.')),
                Markup::e($base.'/konto'), Icons::mini('user'), Markup::e($page->t('Profil anlegen')));
        } else {
            $profile = $this->profile;
            $rank = app(Recognition::class)->ranksOf($page->site, collect([$profile->customer_id => $profile]))[$profile->customer_id] ?? null;
            $followers = count(app(Relations::class)->followers($profile->customer_id));
            $html = sprintf('<div class="konto-profil">%s<div class="konto-profil__text"><b>%s</b><p class="konto-profil__zahlen">%s<span><b>%s</b> %s</span><span><b>%s</b> %s</span><span><b>%s</b> %s</span></p></div><div class="konto-knoepfe"><a class="konto-knopf" href="%s">%s %s</a></div></div>',
                AccountHub::avatar($profile, $profile->name), Markup::e($profile->name), $rank === null ? '' : '<span class="konto-chip">'.Markup::e($rank->title).'</span>',
                Markup::number($profile->posts, $lang), Markup::e(trans_choice('Beitrag|Beiträge', $profile->posts, [], $lang)),
                Markup::number($profile->reactions_received, $lang), Markup::e($page->t('Reaktionen')), Markup::number($followers, $lang), Markup::e($page->t('Follower')),
                Markup::e($base.'/konto'), Icons::mini('pencil'), Markup::e($page->t('Profil bearbeiten')));
        }
        if ($this->latest->isNotEmpty()) {
            $rows = '';
            foreach ($this->latest as $row) {
                $new = $row->getAttribute('read_at') === null;
                $at = $row->getAttribute('created_at');
                $when = $at === null ? null : Carbon::parse($at);
                $when?->locale($lang);
                $rows .= sprintf('<li class="konto-zeile"><span class="konto-sechseck konto-sechseck--klein%s" aria-hidden="true">%s</span><div class="konto-zeile__text"><a href="%s">%s</a><small>%s</small></div></li>',
                    $new ? ' konto-sechseck--lava' : ' konto-sechseck--grau', Icons::svg('bell'), Markup::e($base.'/benachrichtigungen/'.$row->id), Markup::e(CommunityNotifications::title($row, $lang)),
                    $when === null ? '' : Markup::e($when->diffForHumans()));
            }
            $html .= sprintf('<h3 class="konto-untertitel">%s</h3><ul class="konto-zeilen">%s</ul>', Markup::e($page->t('Benachrichtigungen')), $rows);
        }
        if ($this->threads !== []) {
            $items = '';
            foreach ($this->threads as $thread) {
                $new = in_array($thread->id, $this->unreadThreads, true);
                $items .= sprintf('<li%s><a href="%s">%s</a><small>%s</small></li>', $new ? ' class="ist-neu"' : '', Markup::e($base.'/thema/'.$thread->pathSegment().($new ? '/ungelesen' : '')), Markup::e($thread->title),
                    Markup::e(trans_choice(':count Antwort|:count Antworten', $thread->replies, ['count' => $thread->replies], $lang)));
            }
            $html .= sprintf('<h3 class="konto-untertitel">%s</h3><ul class="konto-themen">%s</ul>', Markup::e($page->t('Ihre Themen')), $items);
        }
        $html .= $this->terms($page);
        $badge = static fn (int $n): string => $n > 0 ? ' <span class="konto-chip konto-chip--lava">'.$n.'</span>' : '';

        return $html.sprintf('<div class="konto-knoepfe"><a class="konto-knopf konto-knopf--lava" href="%s">%s %s</a><a class="konto-knopf" href="%s">%s %s%s</a><a class="konto-knopf" href="%s">%s %s%s</a></div>',
            Markup::e($base), Icons::mini('chat'), Markup::e($page->t('Zum Forum')), Markup::e($base.'/benachrichtigungen'), Icons::mini('bell'), Markup::e($page->t('Benachrichtigungen')), $badge($this->unread),
            Markup::e($base.'/konversationen'), Icons::mini('mail'), Markup::e($page->t('Nachrichten')), $badge($this->messages));
    }

    /**
     * CR-011 TB 60: Zustimmung zu den Bedingungen der Community — wann und zu welcher Fassung, mit «Widerrufen»; ohne
     * gültige Zustimmung der Weg ins Forum, wo das Fenster wartet.
     */
    private function terms(AccountPage $page): string
    {
        $terms = app(CommunityTerms::class);
        $site = $page->site;
        $customer = $page->customer;
        if ($customer === null || ! $terms->required($site)) {
            return '';
        }
        $lang = $page->lang;
        $current = $terms->current($site, $customer);
        $latest = $terms->latest($site, $customer);
        $zone = $site->timezone;
        $links = implode(' · ', array_map(static fn (array $link): string => sprintf('<a href="%s">%s</a>', Markup::e($link['url']), Markup::e($link['label'])), $terms->links($site, $lang)));
        if ($current !== null) {
            $at = $current->accepted_at->timezone($zone);
            $state = sprintf('<p>%s</p>', Markup::e($page->t('Zugestimmt am :date um :time Uhr (Fassung :version).', ['date' => $at->format('d.m.Y'), 'time' => $at->format('H:i'), 'version' => $current->version])));
            $action = sprintf('<details class="konto-klappe"><summary>%s %s</summary><form class="konto-klappe__inhalt" method="post" action="%s">%s<p>%s</p><button type="submit" class="konto-knopf">%s</button></form></details>',
                Icons::mini('x'), Markup::e($page->t('Zustimmung widerrufen')), Markup::e(route('basalto.community.terms.revoke', [], false)), Html::hidden($page->path, $lang, true),
                Markup::e($page->t('Ohne Zustimmung können Sie die Community nicht mehr nutzen — schreiben, reagieren und Nachrichten senden geht erst nach einer neuen Zustimmung. Ihre Beiträge bleiben.')),
                Markup::e($page->t('Zustimmung jetzt widerrufen')));
        } else {
            $state = sprintf('<p>%s</p>', Markup::e(match (true) {
                $latest?->revoked_at !== null => $page->t('Zustimmung widerrufen am :date um :time Uhr — die Community ist gesperrt, bis Sie neu zustimmen.',
                    ['date' => $latest->revoked_at->timezone($zone)->format('d.m.Y'), 'time' => $latest->revoked_at->timezone($zone)->format('H:i')]),
                $latest !== null => $page->t('Die Bedingungen haben eine neue Fassung — bitte stimmen Sie neu zu.'),
                default => $page->t('Noch nicht zugestimmt — Sie stimmen zu, wenn Sie das Forum öffnen.'),
            }));
            $action = sprintf('<div class="konto-knoepfe"><a class="konto-knopf konto-knopf--lava" href="%s">%s %s</a></div>', Markup::e($this->base), Icons::mini('check'), Markup::e($page->t('Im Forum zustimmen')));
        }

        return sprintf('<h3 class="konto-untertitel" id="bedingungen">%s</h3><div class="konto-bedingungen">%s%s%s</div>', Markup::e($page->t('Bedingungen der Community')), $state,
            $links === '' ? '' : '<p class="konto-bedingungen__links">'.$links.'</p>', $action);
    }

    private function baseOf(AccountPage $page): ?string
    {
        if (! app(Backend::class)->moduleEnabled('forum', $page->site)) {
            return null;
        }

        return app(Mounts::class)->baseFor($page->site, $page->lang);
    }
}
