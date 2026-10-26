<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Content\Pages;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Models\Site;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Carbon;

/**
 * Kästen der Community (CR-006 FO-13): Statistik, wer gerade online ist, letzte und heisse Themen — in der Seitenleiste
 * der Forenübersicht und als Lava-Teile (`forum.latest`, `forum.hot`, `forum.stats`, `community.online`) für jede
 * Region eines Layouts. Nur Themen aus Foren, die die Person lesen darf. «Online» heisst: in den letzten 15 Minuten
 * aktiv (für Gäste kommt die Übersicht aus dem Seiten-Cache, dort ist es so alt wie die Seite).
 */
final class Widgets
{
    public const ONLINE_MINUTES = 15;

    public function __construct(private readonly Forum $forum) {}

    /**
     * Die Zahlen für den Puls der Übersicht (TB 47): Themen, Beiträge, Mitglieder und wer gerade online ist.
     *
     * @return array{threads: int, posts: int, members: int, online: list<CommunityProfile>}
     */
    public function figures(Site $site): array
    {
        return [
            'threads' => Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->count(),
            'posts' => (int) Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('parent_id')->sum('posts_count'),
            'members' => CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->count(),
            'online' => array_values(CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('last_activity_at', '>=', now()->subMinutes(self::ONLINE_MINUTES))
                ->orderByDesc('last_activity_at')->limit(30)->get()->all()),
        ];
    }

    public function stats(Site $site, string $lang): string
    {
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->count();
        $posts = (int) Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('parent_id')->sum('posts_count');
        $members = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->count();
        $newest = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->latest('id')->value('name');
        $rows = sprintf('<div><dt>%s</dt><dd>%s</dd></div><div><dt>%s</dt><dd>%s</dd></div><div><dt>%s</dt><dd>%s</dd></div>',
            Markup::e(__('Themen', [], $lang)), Markup::number($threads, $lang), Markup::e(__('Beiträge', [], $lang)), Markup::number($posts, $lang),
            Markup::e(__('Mitglieder', [], $lang)), Markup::number($members, $lang));
        if (is_string($newest)) {
            $rows .= sprintf('<div><dt>%s</dt><dd>%s</dd></div>', Markup::e(__('Neuestes Mitglied', [], $lang)), Markup::e($newest));
        }

        return $this->box(__('Statistik', [], $lang), '<dl class="forum-kasten__zahlen">'.$rows.'</dl>');
    }

    public function online(Site $site, string $lang): string
    {
        $profiles = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('last_activity_at', '>=', now()->subMinutes(self::ONLINE_MINUTES))
            ->orderByDesc('last_activity_at')->limit(30)->get();
        $list = $profiles->isEmpty() ? sprintf('<p class="forum-kasten__leer">%s</p>', Markup::e(__('Gerade niemand.', [], $lang)))
            : '<ul class="forum-kasten__namen">'.implode('', $profiles->map(static fn (CommunityProfile $p): string => sprintf('<li%s>%s %s</li>', $p->isTeam() ? ' class="ist-team"' : '',
                Markup::avatar($p, $p->name, 'klein'), Markup::e($p->name)))->all()).'</ul>';

        return $this->box(trans_choice(':count Mitglied online|:count Mitglieder online', $profiles->count(), ['count' => $profiles->count()], $lang), $list, 'users');
    }

    /** Letzte (nach letzter Antwort) oder heisse Themen (meiste Antworten der letzten sieben Tage). */
    public function threads(Site $site, ?Customer $customer, string $lang, string $base, bool $hot = false, int $limit = 5): string
    {
        $readable = $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, $customer, Forum::READ, $n))->keys()->all();
        $query = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->whereIn('node_id', $readable);
        $threads = $hot ? $query->where('last_posted_at', '>=', now()->subDays(7))->orderByDesc('replies')->orderByDesc('last_posted_at')->limit($limit)->get()
            : $query->orderByDesc('last_posted_at')->limit($limit)->get();
        if ($threads->isEmpty()) {
            return $this->box($hot ? __('Heisse Themen', [], $lang) : __('Letzte Beiträge', [], $lang), sprintf('<p class="forum-kasten__leer">%s</p>', Markup::e(__('Noch nichts.', [], $lang))), $hot ? 'flame' : 'zap');
        }
        $people = new People($site, $threads->pluck('last_customer_id')->all());
        $items = '';
        foreach ($threads as $thread) {
            $items .= sprintf('<li class="mit-bild">%s<div><a href="%s">%s</a><span>%s · %s</span></div></li>', $people->avatar($thread->last_customer_id, $lang, 'klein'), Markup::e($base.'/thema/'.$thread->pathSegment().'/ungelesen'), Markup::e($thread->title),
                Markup::e($hot ? trans_choice(':count Antwort|:count Antworten', $thread->replies, ['count' => $thread->replies], $lang) : $people->name($thread->last_customer_id, $lang)),
                Markup::time($thread->last_posted_at, $lang));
        }

        return $this->box($hot ? __('Heisse Themen', [], $lang) : __('Letzte Beiträge', [], $lang), '<ul class="forum-kasten__themen">'.$items.'</ul>', $hot ? 'flame' : 'zap');
    }

    /** TB 41 (FO-21): vom Team empfohlene Themen (Lava-Teil `forum.featured`) — leer, wenn es keine gibt. */
    public function featured(Site $site, ?Customer $customer, string $lang, string $base, int $limit = 5): string
    {
        $readable = $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, $customer, Forum::READ, $n))->keys()->all();
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_featured', true)->where('is_disabled', false)->whereNull('moved_to_id')
            ->whereIn('node_id', $readable)->orderByDesc('last_posted_at')->limit($limit)->get();
        if ($threads->isEmpty()) {
            return '';
        }
        $items = '';
        foreach ($threads as $thread) {
            $items .= sprintf('<li><a href="%s">%s</a><span>%s</span></li>', Markup::e($base.'/thema/'.$thread->pathSegment()), Markup::e($thread->title),
                Markup::e(trans_choice(':count Antwort|:count Antworten', $thread->replies, ['count' => $thread->replies], $lang)));
        }

        return $this->box(__('Empfohlen', [], $lang), '<ul class="forum-kasten__themen">'.$items.'</ul>', 'star');
    }

    /**
     * «Aus unserem Blog» (Vorbild WoltLab): die neuesten veröffentlichten Beiträge des Blogs der Site — leer ohne Blog.
     */
    public function blog(Site $site, string $lang, int $limit = 5): string
    {
        $pages = app(Pages::class);
        $items = [];
        foreach ($pages->entries($site) as $entry) {
            if ($entry->type !== 'post') {
                continue;
            }
            $entry->setRelation('site', $site);
            $version = $pages->versionOf($entry, $lang) ?? $pages->versionOf($entry, $site->default_locale);
            $url = $version === null ? null : ($pages->urlOf($entry, $lang) ?? $pages->urlOf($entry, $site->default_locale));
            if ($version !== null && $url !== null) {
                $items[] = ['title' => (string) $version->title, 'url' => $url, 'date' => $version->published_at];
            }
        }
        if ($items === []) {
            return '';
        }
        usort($items, static fn (array $a, array $b): int => ($b['date']?->getTimestamp() ?? 0) <=> ($a['date']?->getTimestamp() ?? 0));
        $html = '';
        foreach (array_slice($items, 0, $limit) as $item) {
            $html .= sprintf('<li><a href="%s">%s</a><span>%s · %s</span></li>', Markup::e($item['url']), Markup::e($item['title']), Markup::e($site->name), Markup::time($item['date'], $lang));
        }

        return $this->box(__('Aus unserem Blog', [], $lang), '<ul class="forum-kasten__themen">'.$html.'</ul>', 'book');
    }

    /**
     * Das Band unter der Übersicht (Vorbild WoltLab): Statistik mit Beiträgen pro Tag und neuestem Mitglied, daneben
     * wer online ist — mit Rekord (Zahl und Tag, in den Einstellungen der Site gemerkt).
     */
    public function band(Site $site, string $lang, string $base): string
    {
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->count();
        $posts = (int) Node::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('parent_id')->sum('posts_count');
        $members = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->count();
        $newest = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->latest('id')->first();
        $first = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->min('created_at');
        $days = $first === null ? 1 : max(1, (int) ceil(Carbon::parse((string) $first)->diffInDays(now(), true)));
        $stats = sprintf('<p>%s · %s · %s (%s)</p>', Markup::e(trans_choice(':count Mitglied|:count Mitglieder', $members, ['count' => Markup::digits($members, $lang)], $lang)),
            Markup::e(trans_choice(':count Thema|:count Themen', $threads, ['count' => Markup::digits($threads, $lang)], $lang)),
            Markup::e(trans_choice(':count Beitrag|:count Beiträge', $posts, ['count' => Markup::digits($posts, $lang)], $lang)),
            Markup::e(__(':count Beiträge pro Tag', ['count' => number_format($posts / $days, 2, $lang === 'en' ? '.' : ',', $lang === 'en' ? ',' : "'")], $lang)));
        if ($newest !== null) {
            $stats .= sprintf('<p>%s <a href="%s">%s</a></p>', Markup::e(__('Unser neuestes Mitglied heisst:', [], $lang)), Markup::e($base.'/mitglied/'.$newest->pathSegment()), Markup::e($newest->name));
        }
        $online = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('last_activity_at', '>=', now()->subMinutes(self::ONLINE_MINUTES))
            ->orderByDesc('user_id')->orderBy('name')->limit(100)->get();
        $settings = app(Settings::class);
        $record = (array) $settings->get('forum.online_record', [], $site);
        if ($online->count() > (int) ($record['count'] ?? 0)) {
            $record = ['count' => $online->count(), 'date' => now()->toDateString()];
            $settings->set('forum.online_record', $record, $site);
        }
        $names = $online->isEmpty() ? Markup::e(__('Gerade niemand.', [], $lang)) : implode(', ', $online->map(static fn (CommunityProfile $p): string => sprintf('<a href="%s"%s>%s</a>',
            Markup::e($base.'/mitglied/'.$p->pathSegment()), $p->isTeam() ? ' class="ist-team"' : '', Markup::e($p->name)))->all());
        $recordText = '';
        if (isset($record['count'], $record['date'])) {
            $day = Carbon::parse((string) $record['date']);
            $day->locale($lang);
            $recordText = ' · '.Markup::e(__('Rekord: :count Mitglieder (:date)', ['count' => (int) $record['count'], 'date' => $day->isoFormat(Markup::dayFormat($lang, false))], $lang));
        }

        return sprintf('<section class="forum-band"><div><h2>%s%s</h2>%s</div><div><h2>%s%s</h2><p>%s%s</p><p class="forum-band__namen">%s</p></div></section>',
            Markup::icon('list-check'), Markup::e(__('Statistik', [], $lang)), $stats, Markup::icon('users'), Markup::e(__('Mitglieder online', [], $lang)),
            Markup::e(trans_choice(':count Mitglied online|:count Mitglieder online', $online->count(), ['count' => $online->count()], $lang)), $recordText, $names);
    }

    /** TB 40: die neuesten Mitglieder (Lava-Teil `forum.members`) — mit Verweis auf ihr Profil. */
    public function members(Site $site, string $lang, string $base, int $limit = 12): string
    {
        $profiles = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('banned_at')->latest('id')->limit($limit)->get();

        return $this->box(__('Neue Mitglieder', [], $lang), $this->names($profiles->all(), $lang, $base)
            .sprintf('<p class="forum-kasten__leer"><a href="%s">%s</a></p>', Markup::e($base.'/mitglieder'), Markup::e(__('Alle Mitglieder', [], $lang))), 'user-plus');
    }

    /** TB 40: das Team (Lava-Teil `forum.team`). */
    public function team(Site $site, string $lang, string $base): string
    {
        $profiles = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNotNull('user_id')->orderBy('name')->limit(30)->get();

        return $this->box(__('Team', [], $lang), $this->names($profiles->all(), $lang, $base), 'shield');
    }

    /** @param  array<int, CommunityProfile>  $profiles */
    private function names(array $profiles, string $lang, string $base): string
    {
        return $profiles === [] ? sprintf('<p class="forum-kasten__leer">%s</p>', Markup::e(__('Noch niemand.', [], $lang)))
            : '<ul class="forum-kasten__namen">'.implode('', array_map(static fn (CommunityProfile $p): string => sprintf('<li%s>%s <a href="%s">%s</a></li>', $p->isTeam() ? ' class="ist-team"' : '',
                Markup::avatar($p, $p->name, 'klein'), Markup::e($base.'/mitglied/'.$p->pathSegment()), Markup::e($p->name)), $profiles)).'</ul>';
    }

    private function box(string $title, string $content, string $icon = 'zap'): string
    {
        return sprintf('<section class="forum-kasten"><h2>%s%s</h2>%s</section>', Markup::icon($icon), Markup::e($title), $content);
    }
}
