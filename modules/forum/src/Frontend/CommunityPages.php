<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum\Frontend;

use Basalto\Core\Community\CommunityNotifications;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRichText;
use Basalto\Core\Community\CommunityTrophy;
use Basalto\Core\Community\CommunityWallPost;
use Basalto\Core\Community\Conversation;
use Basalto\Core\Community\ConversationMessage;
use Basalto\Core\Community\Conversations;
use Basalto\Core\Community\Islands;
use Basalto\Core\Community\MountResult;
use Basalto\Core\Community\ProfileFields;
use Basalto\Core\Community\Profiles;
use Basalto\Core\Community\ProfileTabs;
use Basalto\Core\Community\Recognition;
use Basalto\Core\Community\Relations;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Sanctions;
use Basalto\Core\Community\Typing;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorGroups;
use Basalto\Core\Community\Wall;
use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\Html;
use Basalto\Core\Live\Live;
use Basalto\Core\Sites\SiteScope;
use Basalto\Modules\Forum\Forum;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Profile, Mitglieder und Konversationen unter der Community-Seite (CR-006 FO-18, FO-19), als Teil von
 * {@see ForumMount}: öffentliches Profil mit Cover, Rang, Trophäen und Reitern aus allen Modulen, Folgen und
 * Blockieren, Mitgliederliste und Team, «Neues von Gefolgten», Konversationen mit mehreren Teilnehmern. Die Logik
 * steckt im Kern (`Relations`, `Conversations`, `Recognition`, `ProfileTabs`) — hier nur das Markup.
 */
trait CommunityPages
{
    /** @var list<int>|null */
    private ?array $blockedIds = null;

    /** @return list<int> wen die angemeldete Person blockiert */
    private function blocked(): array
    {
        return $this->blockedIds ??= app(Relations::class)->blocked($this->customer);
    }

    private function profileUrl(CommunityProfile $profile): string
    {
        return $this->r->url('mitglied/'.$profile->pathSegment());
    }

    // ── Profil ──────────────────────────────────────────────────

    private function memberProfile(string $segment): MountResult
    {
        $site = $this->r->site;
        $profile = preg_match('/^(\d+)(?:-[a-z0-9-]*)?$/', $segment, $m) === 1
            ? CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('customer_id', (int) $m[1])->first() : null;
        $owner = $profile === null ? null : Customer::query()->withoutGlobalScopes()->find($profile->customer_id);
        if ($profile === null || $owner === null) {
            return MountResult::notFound();
        }
        if ($segment !== $profile->pathSegment()) {
            // Der Reiter bleibt beim Weg auf die vollständige Adresse erhalten (`/mitglied/4?reiter=pinnwand`)
            $tab = $this->r->query('reiter');

            return MountResult::redirect($this->r->url('mitglied/'.$profile->pathSegment(), ['reiter' => preg_match('/^[a-z]{1,20}$/', $tab) === 1 ? $tab : null]));
        }
        $lang = $this->lang();
        $people = new People($site, [$owner->id]);
        $relations = app(Relations::class);
        $self = $this->customer !== null && $this->customer->id === $owner->id;
        $page = $this->profileUrl($profile);

        // Kopf: Cover, Bild, Name, Abzeichen, Rang, Zahlen, Aktionen
        $badge = $people->badge($owner->id);
        $rank = $people->rank($owner->id);
        $followers = $relations->followers($owner->id);
        $following = $relations->following($owner->id);
        $stats = [
            [$this->t('Beiträge'), Markup::number($profile->posts, $lang)],
            [$this->t('Reaktionen erhalten'), Markup::number($profile->reactions_received, $lang)],
            [$this->t('Follower'), Markup::number(count($followers), $lang)],
            [$this->t('Mitglied seit'), Markup::time($profile->created_at, $lang)],
        ];
        $statsHtml = implode('', array_map(fn (array $s): string => sprintf('<div><dt>%s</dt><dd>%s</dd></div>', $this->e($s[0]), $s[1]), $stats));
        $actions = '';
        if ($self) {
            $actions = sprintf('<a class="forum-aktion" href="%s">%s %s</a>', $this->e($this->r->url('konto')), Markup::mini('pencil'), $this->e($this->t('Profil bearbeiten')));
        } elseif ($this->customer !== null && $this->profiles->of($this->customer) !== null) {
            $isBlocked = $relations->blocks($this->customer->id, $owner->id);
            if (! $isBlocked) {
                $follows = $relations->follows($this->customer, $owner->id);
                $actions .= Activity::button(route('basalto.community.follow', absolute: false), ['konto' => $owner->id, 'modus' => $follows ? 'entfolgen' : 'folgen'],
                    $follows ? $this->t('Folgen beenden') : $this->t('Folgen'), $page, $lang, $follows, 'user-plus');
                if (! app(Sanctions::class)->isMuted($this->customer) && ! $relations->blocks($owner->id, $this->customer->id)) {
                    $actions .= sprintf('<a class="forum-button" href="%s">%s %s</a>', $this->e($this->r->url('konversationen/neu', ['an' => $profile->name])), Markup::mini('mail'), $this->e($this->t('Nachricht senden')));
                }
            }
            if (! $profile->isTeam()) {
                $actions .= Activity::button(route('basalto.community.block', absolute: false), ['konto' => $owner->id, 'modus' => $isBlocked ? 'freigeben' : 'blockieren'],
                    $isBlocked ? $this->t('Nicht mehr blockieren') : $this->t('Blockieren'), $page, $lang, $isBlocked, 'ban', 'leise');
            }
        }
        $cover = $profile->coverUrl();
        $head = sprintf('<section class="community-profil">%s<div class="community-profil__kopf">%s<div class="community-profil__name"><h2>%s</h2><p>%s%s%s</p></div>'
            .'<dl class="community-profil__zahlen">%s</dl><div class="forum-post__aktionen">%s</div></div></section>',
            $cover === null ? '<div class="community-profil__cover community-profil__cover--leer"></div>' : sprintf('<div class="community-profil__cover"><img src="%s" alt="" width="1500" height="400"></div>', $this->e($cover)),
            $people->avatar($owner->id, $lang, 'profil'), $this->e($profile->name),
            $badge === null ? '' : sprintf('<span class="forum-badge%s">%s</span> ', $profile->isTeam() ? ' forum-badge--team' : '', $this->e($this->t($badge))),
            $rank === null ? '' : Markup::rank($rank).' ',
            $profile->last_activity_at === null ? '' : '<span>'.Markup::mini('clock').' '.$this->e($this->t('Zuletzt aktiv')).' '.Markup::time($profile->last_activity_at, $lang).'</span>',
            $statsHtml, $actions);

        // Reiter
        $tabs = ['ueber' => $this->t('Über')];
        // TB 52: Pinnwand — sichtbar, sobald es Einträge gibt oder die Person hier schreiben darf
        $wall = app(Wall::class);
        $wallCount = $wall->count($profile);
        if ($wallCount > 0 || $wall->canWrite($site, $this->customer, $owner)) {
            $tabs['pinnwand'] = $wallCount > 0 ? $this->t('Pinnwand').' · '.$wallCount : $this->t('Pinnwand');
        }
        foreach (app(ProfileTabs::class)->all() as $key => $tab) {
            if (($tab['count'])($site, $owner) > 0) {
                $tabs[$key] = $this->t($tab['label']);
            }
        }
        $trophies = $people->trophies($owner->id);
        if ($trophies !== []) {
            $tabs['trophaeen'] = $this->t('Trophäen');
        }
        $tabs['folgt'] = $this->t('Folgt');
        $tabs['follower'] = $this->t('Follower');
        $active = array_key_exists((string) $this->r->query('reiter'), $tabs) ? (string) $this->r->query('reiter') : 'ueber';
        $nav = '';
        foreach ($tabs as $key => $label) {
            $nav .= sprintf('<li><a href="%s"%s>%s</a></li>', $this->e($this->r->url('mitglied/'.$profile->pathSegment(), ['reiter' => $key === 'ueber' ? null : $key])), $key === $active ? ' aria-current="page"' : '', $this->e($label));
        }
        $body = match ($active) {
            'ueber' => $this->aboutTab($profile, $owner),
            'trophaeen' => '<ul class="community-trophaeen">'.implode('', array_map(fn (CommunityTrophy $t): string => sprintf('<li><span class="community-trophaeen__icon" aria-hidden="true">%s</span><div><b>%s</b>%s</div></li>',
                $this->e($t->icon), $this->e($t->title), $t->description === null ? '' : '<p>'.$this->e($t->description).'</p>'), $trophies)).'</ul>',
            'pinnwand' => $this->wallTab($profile, $owner, $page),
            'folgt' => $this->peopleList($following, $this->t('Folgt noch niemandem.')),
            'follower' => $this->peopleList($followers, $this->t('Noch keine Follower.')),
            default => (app(ProfileTabs::class)->all()[$active]['render'])($site, $owner, $this->customer, $lang, $this->r->base),
        };
        $html = $this->bar().$head.sprintf('<nav class="forum-filter" aria-label="%s"><ul>%s</ul></nav><section class="forum-section community-reiter">%s</section>', $this->e($this->t('Profil')), $nav, $body);
        $crumbs = [['label' => $this->t('Mitglieder'), 'href' => $this->r->url('mitglieder')], ['label' => $profile->name, 'href' => $page]];

        return $this->page($html, $profile->name, $crumbs, $profile->about !== null ? mb_substr($profile->about, 0, 160) : null);
    }

    private function aboutTab(CommunityProfile $profile, Customer $owner): string
    {
        $html = $profile->about !== null && trim($profile->about) !== '' ? sprintf('<div class="community-ueber">%s</div>', nl2br($this->e($profile->about), false)) : '';
        $values = $profile->fields ?? [];
        $rows = '';
        foreach (app(ProfileFields::class)->definitions($this->r->site) as $field) {
            $value = $values[$field['key']] ?? '';
            if ($value === '' || ! app(ProfileFields::class)->visible($this->r->site, $field, $this->customer, $owner)) {
                continue;
            }
            $shown = $field['type'] === 'url' ? sprintf('<a href="%s" rel="nofollow ugc noopener">%s</a>', $this->e($value), $this->e(preg_replace('~^https?://~', '', $value))) : nl2br($this->e($value), false);
            $rows .= sprintf('<div><dt>%s</dt><dd>%s</dd></div>', $this->e($field['label']), $shown);
        }
        $html .= $rows === '' ? '' : '<dl class="forum-felder">'.$rows.'</dl>';

        return $html === '' ? sprintf('<p class="forum-empty">%s</p>', $this->e($this->t('Noch keine Angaben.'))) : '<div class="community-reiter__inhalt">'.$html.'</div>';
    }

    /** @param  list<int>  $ids */
    private function peopleList(array $ids, string $empty): string
    {
        if ($ids === []) {
            return sprintf('<p class="forum-empty">%s</p>', $this->e($empty));
        }
        $profiles = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $this->r->site->id)->whereIn('customer_id', array_slice($ids, 0, 200))->orderBy('name')->get();

        return $this->memberCards(array_values($profiles->all()));
    }

    /** @param  list<CommunityProfile>  $profiles */
    private function memberCards(array $profiles): string
    {
        $lang = $this->lang();
        $people = new People($this->r->site, array_map(static fn (CommunityProfile $p): int => $p->customer_id, $profiles));
        $items = '';
        foreach ($profiles as $profile) {
            $badge = $people->badge($profile->customer_id);
            $rank = $people->rank($profile->customer_id);
            $items .= sprintf('<li class="community-karte">%s<div><a href="%s"><b>%s</b></a><p>%s%s%s</p></div></li>', $people->avatar($profile->customer_id, $lang),
                $this->e($this->profileUrl($profile)), $this->e($profile->name),
                $badge === null ? '' : sprintf('<span class="forum-badge%s">%s</span> ', $profile->isTeam() ? ' forum-badge--team' : '', $this->e($this->t($badge))),
                $rank === null ? '' : Markup::rank($rank).' ',
                $this->e(trans_choice(':count Beitrag|:count Beiträge', $profile->posts, ['count' => Markup::digits($profile->posts, $lang)], $lang)));
        }

        return '<ul class="community-karten">'.$items.'</ul>';
    }

    // ── Mitglieder, Team, Neues von Gefolgten ───────────────────

    private function members(): MountResult
    {
        $site = $this->r->site;
        $lang = $this->lang();
        $q = trim(mb_substr((string) $this->r->query('q'), 0, 30));
        $sort = in_array($this->r->query('sortierung'), ['beitraege', 'neu'], true) ? (string) $this->r->query('sortierung') : 'name';
        $query = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)
            ->where(static fn ($w) => $w->whereNull('banned_at')->orWhere('banned_until', '<', now()))
            ->when($q !== '', static fn ($w) => $w->where('name_key', 'like', '%'.Profiles::key($q).'%'));
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / 30));
        $page = min($this->r->pageNumber(), $pages);
        match ($sort) {
            'beitraege' => $query->orderByDesc('posts')->orderBy('name'),
            'neu' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderBy('name_key'),
        };
        $profiles = $query->forPage($page, 30)->get();
        $sorts = '';
        foreach (['name' => $this->t('Name'), 'beitraege' => $this->t('Meiste Beiträge'), 'neu' => $this->t('Neueste')] as $key => $label) {
            $sorts .= sprintf('<li><a href="%s"%s>%s</a></li>', $this->e($this->r->url('mitglieder', ['q' => $q !== '' ? $q : null, 'sortierung' => $key === 'name' ? null : $key])), $key === $sort ? ' aria-current="true"' : '', $this->e($label));
        }
        $search = sprintf('<form class="forum-suche" method="get" action="%s"><label for="mitglieder-suche" class="visually-hidden">%s</label><input id="mitglieder-suche" type="search" name="q" value="%s" placeholder="%2$s"><button type="submit" class="forum-button">%s</button></form>',
            $this->e($this->r->url('mitglieder')), $this->e($this->t('Name suchen')), $this->e($q), $this->e($this->t('Suchen')));
        $list = $profiles->isEmpty() ? sprintf('<p class="forum-empty">%s</p>', $this->e($this->t('Keine Mitglieder gefunden.'))) : $this->memberCards(array_values($profiles->all()));
        $html = $this->bar().$search.sprintf('<nav class="forum-filter" aria-label="%s"><ul>%s</ul></nav><section class="forum-section">%s</section>', $this->e($this->t('Sortierung')), $sorts, $list)
            .Markup::pagination($page, $pages, fn (int $p): string => $this->r->url('mitglieder', ['q' => $q !== '' ? $q : null, 'sortierung' => $sort === 'name' ? null : $sort, 'seite' => $p > 1 ? $p : null]), $lang);

        return $this->page($html, $this->t('Mitglieder'), [['label' => $this->t('Mitglieder'), 'href' => $this->r->url('mitglieder')]], null, $q !== '', $q !== '');
    }

    private function team(): MountResult
    {
        $site = $this->r->site;
        $moderators = DB::table('visitor_group_customer')->where('visitor_group_id', app(VisitorGroups::class)->byKey($site, VisitorGroup::MODERATORS)->id)->pluck('customer_id')->all();
        $team = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNotNull('user_id')->orderBy('name')->get();
        $mods = CommunityProfile::query()->withoutGlobalScopes()->where('site_id', $site->id)->whereNull('user_id')->whereIn('customer_id', $moderators)->orderBy('name')->get();
        $html = $this->bar();
        foreach ([[$this->t('Team'), $team, 'shield'], [$this->t('Moderation'), $mods, 'users']] as [$title, $profiles, $icon]) {
            if ($profiles->isNotEmpty()) {
                $html .= sprintf('<section class="forum-section"><h2>%s%s</h2>%s</section>', Markup::icon($icon), $this->e($title), $this->memberCards(array_values($profiles->all())));
            }
        }
        if ($team->isEmpty() && $mods->isEmpty()) {
            $html .= sprintf('<p class="forum-empty">%s</p>', $this->e($this->t('Noch niemand im Team.')));
        }

        return $this->page($html, $this->t('Team'), [['label' => $this->t('Team'), 'href' => $this->r->url('team')]]);
    }

    /** Neues von Gefolgten: Themen und Antworten der Personen, denen man folgt (lesbare Foren). */
    private function followedActivity(): MountResult
    {
        $title = $this->t('Neues von Gefolgten');
        $crumbs = [['label' => $title, 'href' => $this->r->url('aktivitaet')]];
        if ($this->customer === null) {
            return $this->page($this->loginNote(), $title, $crumbs, null, true, true);
        }
        $site = $this->r->site;
        $lang = $this->lang();
        $following = app(Relations::class)->following($this->customer->id);
        $readable = $this->forum->nodes($site)->filter(fn (Node $n): bool => $this->forum->can($site, $this->customer, Forum::READ, $n))->keys()->all();
        $threads = Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->whereIn('node_id', $readable)->select('id');
        $posts = $following === [] ? collect() : Post::query()->withoutGlobalScope(SiteScope::class)->whereIn('customer_id', $following)->where('is_disabled', false)
            ->whereIn('thread_id', $threads)->orderByDesc('id')->limit(30)->get();
        $people = new People($site, $posts->pluck('customer_id')->all());
        $titles = Thread::query()->withoutGlobalScope(SiteScope::class)->whereIn('id', $posts->pluck('thread_id'))->get()->keyBy('id');
        $items = '';
        foreach ($posts as $post) {
            $thread = $titles->get($post->thread_id);
            if ($thread === null) {
                continue;
            }
            $items .= sprintf('<li class="forum-fall">%s<p class="forum-fall__kopf">%s %s · %s</p><p><a href="%s">%s</a></p><blockquote>%s</blockquote></li>',
                $people->avatar($post->customer_id, $lang), $people->link($post->customer_id, $lang, fn (string $p): string => $this->r->url($p)),
                $this->e($thread->first_post_id === $post->id ? $this->t('hat ein Thema eröffnet') : $this->t('hat geantwortet')), Markup::time($post->created_at, $lang),
                $this->e($this->r->url('beitrag/'.$post->id)), $this->e($thread->title), $this->e(mb_substr(RichText::toText($post->body), 0, 200)));
        }
        $list = $items === '' ? sprintf('<section class="forum-section"><p class="forum-empty">%s</p></section>', $this->e($following === [] ? $this->t('Sie folgen noch niemandem. Im Profil einer Person finden Sie «Folgen».') : $this->t('Noch nichts Neues.')))
            : sprintf('<section class="forum-section"><ol class="forum-faelle">%s</ol></section>', $items);

        return $this->page($this->bar().$list, $title, $crumbs, null, true, true);
    }

    // ── Konversationen ──────────────────────────────────────────

    private function conversationList(): MountResult
    {
        $title = $this->t('Konversationen');
        $crumbs = [['label' => $title, 'href' => $this->r->url('konversationen')]];
        if ($this->customer === null) {
            return $this->page($this->loginNote(), $title, $crumbs, null, true, true);
        }
        $lang = $this->lang();
        $service = app(Conversations::class);
        $list = $service->of($this->customer);
        $unread = $service->unreadIds($this->customer);
        $participants = DB::table('community_conversation_participants')->whereIn('conversation_id', $list->pluck('id'))->whereNull('left_at')->get()->groupBy('conversation_id');
        $people = new People($this->r->site, $participants->flatten()->pluck('customer_id')->all());
        $items = '';
        foreach ($list as $conversation) {
            $others = $participants->get($conversation->id, collect())->pluck('customer_id')->map(static fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $id !== $this->customer->id)->values();
            $names = $others->map(fn (int $id): string => $people->name($id, $lang))->implode(', ');
            $new = in_array($conversation->id, $unread, true);
            $items .= sprintf('<li class="forum-nachricht%s">%s<a href="%s">%s</a><p>%s</p><span>%s · %s</span></li>', $new ? ' forum-nachricht--neu' : '',
                Markup::stack(array_values($others->take(3)->map(fn (int $id): string => $people->avatar($id, $lang, 'klein'))->all()), max(0, $others->count() - 3)),
                $this->e($this->r->url('konversationen/'.$conversation->id)), $this->e($conversation->subject), $this->e($this->t('Mit :names', ['names' => $names !== '' ? $names : $this->t('niemandem mehr')])),
                $this->e(trans_choice(':count Nachricht|:count Nachrichten', $conversation->messages, ['count' => $conversation->messages], $lang)), Markup::time($conversation->last_message_at, $lang));
        }
        $action = app(Sanctions::class)->isMuted($this->customer) ? '' : sprintf('<a class="forum-button" href="%s">%s %s</a>', $this->e($this->r->url('konversationen/neu')), Markup::mini('plus'), $this->e($this->t('Neue Konversation')));
        $html = $this->bar($action).($items === '' ? sprintf('<section class="forum-section"><div class="forum-leer"><span class="forum-sechseck" aria-hidden="true">%s</span><p>%s</p></div></section>', Markup::icon('chat'), $this->e($this->t('Noch keine Konversationen. Schreiben Sie jemandem aus seinem Profil heraus.')))
            : sprintf('<section class="forum-section"><ol class="forum-nachrichten">%s</ol></section>', $items));

        return $this->page($html, $title, $crumbs, null, true, true);
    }

    private function newConversation(): MountResult
    {
        $title = $this->t('Neue Konversation');
        $crumbs = [['label' => $this->t('Konversationen'), 'href' => $this->r->url('konversationen')], ['label' => $title, 'href' => $this->r->url('konversationen/neu')]];
        if ($this->customer === null) {
            return $this->page($this->loginNote(), $title, $crumbs, null, true, true);
        }
        if ($this->profiles->of($this->customer) === null) {
            return $this->page($this->profileFirst(), $title, $crumbs, null, true, true);
        }
        if (app(Sanctions::class)->isMuted($this->customer)) {
            return $this->page($this->bar(), $title, $crumbs, null, true, true);
        }
        $lang = $this->lang();
        $errors = Html::errors($this->r->request, 'forum');
        $old = Html::old($this->r->request);
        $to = is_string($old['an'] ?? null) ? $old['an'] : (string) $this->r->query('an');
        $form = $this->formStart(route('basalto.community.conversations.start', absolute: false), $title, $this->r->url('konversationen/neu'), $errors);
        $form .= Html::field('konv-an', 'an', $this->e($this->t('An')).Html::required(),
            sprintf('<input id="konv-an" name="an" type="text" required maxlength="600" value="%s"{aria}>', $this->e($to)), $this->t('Anzeigenamen, mit Komma getrennt — höchstens :max.', ['max' => Conversations::MAX_PARTICIPANTS - 1]), $errors);
        $form .= Html::field('konv-betreff', 'betreff', $this->e($this->t('Betreff')).Html::required(),
            sprintf('<input id="konv-betreff" name="betreff" type="text" required maxlength="160" value="%s"{aria}>', $this->e(is_string($old['betreff'] ?? null) ? $old['betreff'] : '')), null, $errors);
        $form .= Html::field('konv-text', 'body', $this->e($this->t('Nachricht')).Html::required(),
            Islands::editor('konv-text', 'body', is_string($old['body'] ?? null) ? $old['body'] : '', $lang, 8, true, ['names' => route('basalto.community.names', absolute: false), 'draft' => 'konv:'.$this->r->site->id.':neu']), null, $errors);
        $form .= sprintf('<p class="form-help">%s</p><p class="form-actions"><button type="submit" class="forum-button">%s</button></p></form>',
            $this->e($this->t('Nur die Teilnehmer lesen die Konversation. Melden Sie eine Nachricht, sieht die Moderation nur diese eine.')), $this->e($this->t('Senden')));

        return $this->page($this->bar().$form, $title, $crumbs, null, true, true);
    }

    private function showConversation(string $id): MountResult
    {
        $service = app(Conversations::class);
        $conversation = ctype_digit($id) ? Conversation::query()->withoutGlobalScopes()->where('site_id', $this->r->site->id)->find((int) $id) : null;
        if ($conversation === null || ! $service->canRead($conversation, $this->customer)) {
            return $this->customer === null ? $this->page($this->loginNote(), $this->t('Konversationen'), [], null, true, true) : MountResult::notFound();
        }
        $lang = $this->lang();
        $page = $this->r->url('konversationen/'.$conversation->id);
        $messages = ConversationMessage::query()->withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('id')->limit(500)->get();
        $participantIds = $service->participants($conversation);
        $people = new People($this->r->site, [...$participantIds, ...$messages->pluck('customer_id')->all()]);
        $service->markRead($conversation, $this->customer);
        $url = $service->url($this->r->site, $conversation);
        if ($url !== null) {
            app(CommunityNotifications::class)->markReadFor($this->customer, $url);
        }
        $items = '';
        foreach ($messages as $message) {
            $text = $message->removed_at !== null ? sprintf('<p class="forum-note">%s</p>', $this->e($this->t('Von der Moderation entfernt.')))
                : CommunityRichText::toHtml($message->body ?? [], $lang);
            $report = $message->removed_at === null && $message->customer_id !== $this->customer->id ? $this->messageReportForm($message, $page) : '';
            $mine = $message->customer_id === $this->customer->id;
            $items .= sprintf('<li class="forum-eintrag"><div class="forum-eintrag__spur" aria-hidden="true">%s</div><article class="forum-post forum-post--nachricht%s" id="nachricht-%d" aria-labelledby="nachricht-%3$d-kopf">'
                .'<header class="forum-post__head" id="nachricht-%3$d-kopf"><span class="forum-post__name">%s</span><span class="forum-post__zeit">%s %s</span></header><div class="forum-post__body"><div class="forum-post__text">%s</div></div>%s</article></li>',
                $people->avatar($message->customer_id, $lang, 'gross'), $mine ? ' forum-post--eigene' : '', $message->id, $people->link($message->customer_id, $lang, fn (string $p): string => $this->r->url($p)),
                Markup::mini('clock'), Markup::time($message->created_at, $lang), $text,
                $report === '' ? '' : '<footer class="forum-post__fuss"><div class="forum-post__werkzeuge">'.$this->menu($this->t('Weitere Aktionen'), $report).'</div></footer>');
        }
        $names = implode(', ', array_map(fn (int $cid): string => $people->link($cid, $lang, fn (string $p): string => $this->r->url($p)), $participantIds));
        $leave = Activity::button(route('basalto.community.conversations.leave', ['conversation' => $conversation->id], false), ['_basis' => $this->r->base], $this->t('Verlassen'), $page, $lang, false, 'logout', 'leise');
        $errors = Html::errors($this->r->request, 'forum');
        $muted = app(Sanctions::class)->isMuted($this->customer);
        $invite = $muted ? '' : sprintf('<details class="forum-menue"><summary>%s %s</summary><div class="forum-menue__liste"><form method="post" action="%s" class="forum-moderation__form">%s<label>%s <input type="text" name="an" required maxlength="600"></label><button type="submit" class="forum-aktion">%s</button></form></div></details>',
            Markup::mini('user-plus'), $this->e($this->t('Einladen')), $this->e(route('basalto.community.conversations.invite', ['conversation' => $conversation->id], false)), Html::hidden($page, $lang, true),
            $this->e($this->t('Anzeigenamen, mit Komma getrennt')), $this->e($this->t('Einladen')));
        $stack = Markup::stack(array_map(fn (int $cid): string => $people->avatar($cid, $lang, 'klein'), array_slice($participantIds, 0, 6)), max(0, count($participantIds) - 6));
        $head = sprintf('<section class="forum-held forum-held--thema" aria-label="%s"><div class="forum-held__text"><p class="forum-held__meta">%s<span>%s %s</span></p></div><div class="forum-held__leiste"><dl class="forum-kennzahlen"><div><dt>%s</dt><dd>%d</dd></div><div><dt>%s</dt><dd>%d</dd></div></dl><div class="forum-held__knoepfe">%s%s</div></div>%s</section>',
            $this->e($this->t('Konversation')), $stack, $this->e($this->t('Teilnehmer:')), $names, $this->e($this->t('Nachrichten')), $messages->count(), $this->e($this->t('Teilnehmer')), count($participantIds), $invite, $leave,
            $errors->has('an') ? sprintf('<p class="form-error" role="alert">%s</p>', $this->e($errors->first('an'))) : '');
        $reply = '';
        if (! $muted) {
            $old = Html::old($this->r->request);
            $reply = $this->formStart(route('basalto.community.conversations.reply', ['conversation' => $conversation->id], false), $this->t('Antworten'), $page, $errors, 'antworten')
                .Html::field('konv-antwort', 'body', $this->e($this->t('Nachricht')).Html::required(),
                    Islands::editor('konv-antwort', 'body', is_string($old['body'] ?? null) ? $old['body'] : '', $lang, 5, true, ['names' => route('basalto.community.names', absolute: false), 'draft' => 'konv:'.$conversation->id,
                        'preview' => route('basalto.community.preview', absolute: false), 'typing' => Typing::key('konversation.'.$conversation->id)]), null, $errors)
                .Islands::typingIndicator($this->r->site->id, 'konversation.'.$conversation->id, $lang, $this->profiles->of($this->customer)?->id, true)
                .sprintf('<p class="form-actions"><button type="submit" class="forum-button">%s %s</button></p></form>', Markup::mini('send'), $this->e($this->t('Senden')));
            $reply = sprintf('<section class="forum-antwort"><div class="forum-eintrag__spur" aria-hidden="true">%s</div>%s</section>', $people->avatar($this->customer->id, $lang, 'gross'), $reply);
        }
        $crumbs = [['label' => $this->t('Konversationen'), 'href' => $this->r->url('konversationen')], ['label' => $conversation->subject, 'href' => $page]];

        // CR-008: neue Nachrichten der anderen — der Hinweis lädt die Konversation neu und springt zur Antwort
        $live = sprintf('<div class="forum-live" role="status" hidden%s><span>%s</span> <a class="forum-button" href="%s#antworten">%s</a></div>',
            app(Live::class)->attributes($this->r->site->id, 'konversation.'.$conversation->id, 'hinweis'), $this->e($this->t('Neue Nachricht in dieser Konversation')), $this->e($page), $this->e($this->t('Anzeigen')));

        // TB 49: «Anna schreibt …» unter den Nachrichten
        $typing = sprintf('<div class="forum-schreibt"><span class="forum-schreibt__spur" aria-hidden="true">%s</span>%s</div>', Markup::icon('chat'),
            Islands::typingIndicator($this->r->site->id, 'konversation.'.$conversation->id, $lang, $this->profiles->of($this->customer)?->id));

        return $this->page($this->bar().$head.sprintf('<ol class="forum-posts" aria-label="%s">%s</ol>', $this->e($this->t('Nachrichten')), $items).$typing.$live.$reply, $conversation->subject, $crumbs, null, true, true);
    }

    /** TB 52: die Pinnwand — Schreibfeld (wer darf) und die Einträge, neueste oben, mit Löschen und Melden. */
    private function wallTab(CommunityProfile $profile, Customer $owner, string $page): string
    {
        $site = $this->r->site;
        $lang = $this->lang();
        $wall = app(Wall::class);
        $entries = $wall->entries($profile, 50);
        $people = new People($site, array_values(array_filter($entries->pluck('customer_id')->all())));
        $html = '';
        if ($wall->canWrite($site, $this->customer, $owner)) {
            $errors = Html::errors($this->r->request, 'forum');
            $html .= $this->formStart(route('basalto.community.wall.store', ['profile' => $profile->id], false), $this->t('Auf die Pinnwand schreiben'), $page, $errors, 'pinnwand-schreiben')
                .Html::field('pinnwand-text', 'body', $this->e($this->t('Ihr Eintrag')).Html::required(),
                    Islands::editor('pinnwand-text', 'body', '', $lang, 3, true, ['names' => route('basalto.community.names', absolute: false), 'preview' => route('basalto.community.preview', absolute: false)]), null, $errors)
                .sprintf('<p class="form-actions"><button type="submit" class="forum-button">%s %s</button></p></form>', Markup::mini('send'), $this->e($this->t('Eintragen')));
        } elseif ($this->customer !== null && $this->customer->id !== $owner->id) {
            $html .= sprintf('<p class="forum-note">%s %s</p>', Markup::mini('lock'), $this->e($wall->mode($owner) === 'gefolgte'
                ? $this->t(':name lässt nur Personen auf die Pinnwand schreiben, denen sie oder er folgt.', ['name' => $profile->name])
                : $this->t(':name hat die Pinnwand geschlossen.', ['name' => $profile->name])));
        }
        $items = '';
        foreach ($entries as $entry) {
            $actions = '';
            if ($wall->canDelete($site, $this->customer, $entry, $owner)) {
                $actions .= sprintf('<form method="post" action="%s">%s<button type="submit" class="forum-aktion forum-aktion--leise">%s %s</button></form>',
                    $this->e(route('basalto.community.wall.destroy', ['post' => $entry->id], false)), Html::hidden($page, $lang, true), Markup::mini('trash'), $this->e($this->t('Löschen')));
            }
            if ($this->customer !== null && $entry->customer_id !== $this->customer->id) {
                $actions .= $this->wallReportForm($entry, $page);
            }
            $items .= sprintf('<li class="community-pinnwand__eintrag" id="pinnwand-%d"><span class="community-pinnwand__bild">%s</span><div class="community-pinnwand__inhalt"><p class="community-pinnwand__kopf"><b>%s</b> %s</p><div class="forum-post__text">%s</div>%s</div></li>',
                $entry->id, $people->avatar($entry->customer_id, $lang), $people->link($entry->customer_id, $lang, $this->r->url(...)), Markup::time($entry->created_at, $lang),
                CommunityRichText::toHtml($entry->body, $lang), $actions === '' ? '' : '<div class="forum-post__aktionen">'.$actions.'</div>');
        }
        $html .= $items === '' ? sprintf('<p class="forum-empty">%s</p>', $this->e($this->t('Noch keine Einträge.'))) : '<ol class="community-pinnwand" id="pinnwand">'.$items.'</ol>';

        return '<div class="community-reiter__inhalt">'.$html.'</div>';
    }

    private function wallReportForm(CommunityWallPost $entry, string $page): string
    {
        $lang = $this->lang();
        $reasons = '';
        foreach (app(Reports::class)->reasons(Wall::REPORT_TYPE, $lang) as $key => $label) {
            $reasons .= sprintf('<label class="community-check"><input type="radio" name="reason" value="%s" required> %s</label>', $this->e($key), $this->e($label));
        }

        return sprintf('<details class="forum-melden"><summary>%s %s</summary><form method="post" action="%s" class="forum-moderation__form">%s%s<input type="hidden" name="subject" value="%s"><input type="hidden" name="id" value="%d"><input type="hidden" name="_anker" value="pinnwand-%d">'
            .'<fieldset><legend>%s</legend>%s</fieldset><label>%s <textarea name="note" rows="2" maxlength="500"></textarea></label><button type="submit" class="forum-aktion">%s</button></form></details>',
            Markup::mini('flag'), $this->e($this->t('Melden')), $this->e(route('basalto.community.report', absolute: false)), Html::hidden($page, $lang, true), Html::honeypot(), Wall::REPORT_TYPE, $entry->id, $entry->id,
            $this->e($this->t('Was stimmt nicht?')), $reasons, $this->e($this->t('Hinweis für die Moderation (freiwillig)')), $this->e($this->t('Meldung senden')));
    }

    private function messageReportForm(ConversationMessage $message, string $page): string
    {
        $lang = $this->lang();
        $reasons = '';
        foreach (app(Reports::class)->reasons(Conversations::REPORT_TYPE, $lang) as $key => $label) {
            $reasons .= sprintf('<label class="community-check"><input type="radio" name="reason" value="%s" required> %s</label>', $this->e($key), $this->e($label));
        }

        return sprintf('<details class="forum-melden"><summary>%s %s</summary><form method="post" action="%s" class="forum-moderation__form">%s%s<input type="hidden" name="subject" value="%s"><input type="hidden" name="id" value="%d"><input type="hidden" name="_anker" value="nachricht-%d">'
            .'<fieldset><legend>%s</legend>%s</fieldset><label>%s <textarea name="note" rows="2" maxlength="500"></textarea></label><button type="submit" class="forum-aktion">%s</button><p class="form-help">%s</p></form></details>',
            Markup::mini('flag'), $this->e($this->t('Melden')), $this->e(route('basalto.community.report', absolute: false)), Html::hidden($page, $lang, true), Html::honeypot(), Conversations::REPORT_TYPE, $message->id, $message->id,
            $this->e($this->t('Was stimmt nicht?')), $reasons, $this->e($this->t('Hinweis für die Moderation (freiwillig)')), $this->e($this->t('Meldung senden')),
            $this->e($this->t('Die Moderation sieht nur diese Nachricht, nicht die ganze Konversation.')));
    }
}
