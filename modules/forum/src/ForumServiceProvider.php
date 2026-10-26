<?php

declare(strict_types=1);

namespace Basalto\Modules\Forum;

use Basalto\Core\Access\Areas;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Community\Attachments;
use Basalto\Core\Community\CommunityAttachment;
use Basalto\Core\Community\CommunityProfile;
use Basalto\Core\Community\CommunityRules;
use Basalto\Core\Community\ModerationLog;
use Basalto\Core\Community\Mounts;
use Basalto\Core\Community\ProfileTabs;
use Basalto\Core\Community\Reports;
use Basalto\Core\Community\Subjects;
use Basalto\Core\Community\VisitorGroup;
use Basalto\Core\Community\VisitorPermission;
use Basalto\Core\Community\VisitorPermissions;
use Basalto\Core\Content\RichText;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\FrontendAccounts\Hub\AccountAreas;
use Basalto\Core\Http\Middleware\RequireCommunityTerms;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\NotificationType;
use Basalto\Core\Notifications\NotificationTypes;
use Basalto\Core\Search\SearchSources;
use Basalto\Core\Seo\Sitemap;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sites\SiteScope;
use Basalto\Core\Testdata\Testdata;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Lava\Runtime\Context;
use Basalto\Lava\Runtime\Registry;
use Basalto\Modules\Forum\Actions\Moderation;
use Basalto\Modules\Forum\Console\ForumBenchmarkCommand;
use Basalto\Modules\Forum\Console\ForumRecountCommand;
use Basalto\Modules\Forum\Console\ForumSeedCommand;
use Basalto\Modules\Forum\Frontend\CommunityArea;
use Basalto\Modules\Forum\Frontend\ForumMount;
use Basalto\Modules\Forum\Frontend\ProfileLists;
use Basalto\Modules\Forum\Frontend\Styles;
use Basalto\Modules\Forum\Frontend\Widgets;
use Basalto\Modules\Forum\Http\Controllers\ActivityController;
use Basalto\Modules\Forum\Http\Controllers\Extranet\CommunityController;
use Basalto\Modules\Forum\Http\Controllers\Extranet\GroupController;
use Basalto\Modules\Forum\Http\Controllers\Extranet\MemberController;
use Basalto\Modules\Forum\Http\Controllers\Extranet\ModerationController as ExtranetModeration;
use Basalto\Modules\Forum\Http\Controllers\Extranet\NodeController;
use Basalto\Modules\Forum\Http\Controllers\Extranet\NoticeController;
use Basalto\Modules\Forum\Http\Controllers\Extranet\PrefixController;
use Basalto\Modules\Forum\Http\Controllers\Extranet\RecognitionController;
use Basalto\Modules\Forum\Http\Controllers\LiveSearchController;
use Basalto\Modules\Forum\Http\Controllers\ModerationController;
use Basalto\Modules\Forum\Http\Controllers\PostingController;
use Basalto\Modules\Forum\Http\Controllers\SimilarController;
use Basalto\Modules\Forum\Http\ForumRoutes;
use Basalto\Modules\Forum\Models\Node;
use Basalto\Modules\Forum\Models\Post;
use Basalto\Modules\Forum\Models\Thread;
use Basalto\Modules\Forum\Privacy\ForumPersonData;
use Basalto\Modules\Forum\Statistics\CommunityStatistics;
use Basalto\Modules\Forum\Testdata\ForumTestdata;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Das Forum als Modul (CR-006, TB 36), je Site schaltbar und ab Werk aus — gedacht zuerst für die Basalto-Community
 * auf basaltocms.com. Bringt mit:
 *
 * - den **Einhängepunkt** `forum`: eine Seite mit «Modul einhängen: Forum» beantwortet `/community` und alles darunter;
 * - die **Besucherrechte** Forum sehen, Themen lesen, Themen eröffnen, antworten, moderieren — global und je Forum;
 * - **Schreiben** aus dem Frontend (Thema eröffnen, antworten) mit dem Kundenkonto des Kerns;
 * - die **Verwaltung im Extranet** unter «Community»: Übersicht mit Team-Verknüpfung, Foren, Gruppen und Rechte.
 */
final class ForumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Forum::class);
    }

    public function boot(): void
    {
        // TB 48: die Community als Bereich des Kundenkontos
        app(AccountAreas::class)->add(CommunityArea::class);
        app(Areas::class)->register('community', 'Community', Areas::EXTRANET, 'forum')->map('basalto.forum.', 'community');
        $root = dirname(__DIR__);
        $this->loadTranslationsFrom($root.'/lang', 'forum');
        $this->loadJsonTranslationsFrom($root.'/lang');
        $this->loadMigrationsFrom($root.'/database/migrations');

        CustomerAuth::useFor('forum');
        app(Mounts::class)->register(Forum::MOUNT, 'Forum', ForumMount::class, 'forum');
        $yes = VisitorPermission::YES;
        app(VisitorPermissions::class)
            ->define(Forum::VIEW, 'Forum sehen', 'Forum', [VisitorGroup::EVERYONE => $yes], Forum::SUBJECT, 'Das Forum erscheint in der Übersicht.')
            ->define(Forum::READ, 'Themen lesen', 'Forum', [VisitorGroup::EVERYONE => $yes], Forum::SUBJECT)
            ->define(Forum::START, 'Themen eröffnen', 'Forum', [VisitorGroup::MEMBERS => $yes], Forum::SUBJECT, writes: true)
            ->define(Forum::REPLY, 'Antworten', 'Forum', [VisitorGroup::MEMBERS => $yes], Forum::SUBJECT, writes: true)
            ->define(Forum::EDIT_OWN, 'Eigene Beiträge bearbeiten', 'Forum', [VisitorGroup::MEMBERS => $yes], Forum::SUBJECT, 'Im Zeitfenster aus den Einstellungen; der Verlauf bleibt sichtbar.', writes: true)
            // TB 39 (FO-17): Freischaltung je Gruppe und Forum — «Nein» für eine Gruppe heisst: erst nach Freigabe sichtbar
            ->define(Forum::NO_APPROVAL, 'Ohne Freischaltung schreiben', 'Forum', [VisitorGroup::MEMBERS => $yes], Forum::SUBJECT,
                'Steht es auf «Nein», erscheinen neue Themen und Antworten erst, wenn die Moderation sie freischaltet.')
            ->define(Forum::MODERATE, 'Moderieren', 'Forum', [VisitorGroup::MODERATORS => $yes, VisitorGroup::TEAM => $yes], Forum::SUBJECT, 'Auch in geschlossenen Foren und Themen schreiben.');

        // TB 38 (FO-11): Benachrichtigungen an Kundenkonten — Glocke im Forum, E-Mail nach Wahl im Profil
        app(NotificationTypes::class)
            ->type(Notifier::REPLY, 'Antwort in einem abonnierten Thema', 'Forum', scope: NotificationType::FRONTEND, mail: true, icon: 'comments')
            ->type(Notifier::THREAD, 'Neues Thema in einem abonnierten Forum', 'Forum', scope: NotificationType::FRONTEND, mail: true, icon: 'comments')
            ->type(Notifier::MENTION, 'Erwähnung', 'Forum', scope: NotificationType::FRONTEND, mail: true, icon: 'comments')
            ->type(Notifier::QUOTE, 'Zitat eines eigenen Beitrags', 'Forum', scope: NotificationType::FRONTEND, mail: true, icon: 'comments')
            ->type(Notifier::REACTION, 'Reaktion auf einen eigenen Beitrag', 'Forum', scope: NotificationType::FRONTEND, icon: 'comments')
            ->type(Notifier::BEST, 'Hilfreichste Antwort', 'Forum', scope: NotificationType::FRONTEND, mail: true, icon: 'comments')
            ->type(Notifier::FOLLOWED, 'Neues Thema einer Person, der Sie folgen', 'Forum', scope: NotificationType::FRONTEND, icon: 'comments');
        $this->recognition();
        // TB 41 (FO-21, FO-22): Themen in der Suche der Site und in der Sitemap
        app(SearchSources::class)->register(ForumSearch::SOURCE, 'Forum', static fn (Site $site) => app(ForumSearch::class)->rebuild($site),
            static fn (Site $site, array $ids, string $query, string $locale): array => app(ForumSearch::class)->hits($site, $ids, $query, $locale));
        $this->app->instance('basalto.sitemap.forum', static fn (Site $site, string $locale, string $base): array => app(Backend::class)->moduleEnabled('forum', $site)
            ? app(ForumSearch::class)->sitemap($site, $locale, $base) : []);
        $this->app->tag(['basalto.sitemap.forum'], Sitemap::TAG);
        // TB 38 (FO-K8): Beiträge für Reaktionen — lesen wie das Thema, Zähler am Beitrag, Nachricht an die Verfasserin
        app(Subjects::class)->register(Forum::ATTACHMENTS,
            static fn (int $id, ?Customer $customer): bool => app(PostingController::class)->readable($id, $customer),
            static fn (int $id): ?int => Post::query()->withoutGlobalScope(SiteScope::class)->whereKey($id)->value('customer_id'),
            static function (int $id, Customer $by, ?string $reaction): void {
                $post = Post::query()->withoutGlobalScope(SiteScope::class)->find($id);
                if ($post === null) {
                    return;
                }
                $post->forceFill(['reactions' => DB::table('community_reactions')->where('subject_type', Forum::ATTACHMENTS)->where('subject_id', $id)->count()])->saveQuietly();
                app(PageCache::class)->invalidate($post->site_id);
                $site = Site::query()->find($post->site_id);
                if ($reaction !== null && $site !== null) {
                    app(Notifier::class)->reacted($site, $by, $post, $reaction);
                }
            });

        // TB 39 (FO-14, FO-15): Beiträge in den Meldungen des Kerns, Bezeichnungen fürs Moderationsprotokoll
        app(Reports::class)->define(Moderation::REPORT_TYPE, 'Beitrag im Forum',
            static function (int $id): ?array {
                $post = Post::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->find($id);
                $thread = $post === null ? null : Thread::query()->withoutGlobalScope(SiteScope::class)->withTrashed()->find($post->thread_id);
                $site = $post === null ? null : Site::query()->find($post->site_id);
                if ($post === null || $thread === null || $site === null) {
                    return null;
                }
                $node = app(Forum::class)->nodes($site)->get($thread->node_id);
                $base = app(Forum::class)->basePath($site, $site->default_locale);

                return ['title' => $thread->title, 'excerpt' => mb_substr(RichText::toText($post->body), 0, 240), 'url' => $base === null ? null : $base.'/beitrag/'.$post->id,
                    'author' => $post->customer_id, 'place' => $node?->titleIn($site->default_locale) ?? ''];
            },
            static function (int $id, int $open): void {
                $post = Post::query()->withoutGlobalScope(SiteScope::class)->find($id);
                if ($post !== null) {
                    app(Moderation::class)->autoHide($post, $open);
                }
            });
        app(ModerationLog::class)->labels(Moderation::LABELS);

        RateLimiter::for('basalto-forum-post', static fn (Request $request): Limit => Limit::perMinute(6)->by('forum|'.(CustomerAuth::current()->id ?? $request->ip())));

        $this->backend($root);
        // Testdaten (basalto:testdaten): Foren wie bei WoltLab mit vielen Themen, Moderation, Rängen und Hinweisen
        app(Testdata::class)->register(ForumTestdata::KEY, 'Forum', 40, static fn (Site $site): bool => app(Backend::class)->moduleEnabled('forum', $site),
            static fn (TestdataRun $run) => app(ForumTestdata::class)->seed($run), static fn (Site $site) => app(ForumTestdata::class)->remove($site));
        if ($this->app->runningInConsole()) {
            $this->commands([ForumRecountCommand::class, ForumSeedCommand::class, ForumBenchmarkCommand::class]);
        }
        // CR-011 TB 60: schreiben, abonnieren, abstimmen und moderieren nur mit Zustimmung zu den Bedingungen
        Route::middleware(['web', 'basalto.site', CustomerAuth::MIDDLEWARE, RequireCommunityTerms::ALIAS, 'throttle:basalto-forum-post'])->group(static function (): void {
            Route::post('forum/threads', [PostingController::class, 'storeThread'])->name('basalto.forum.threads.store');
            Route::post('forum/threads/{thread}/replies', [PostingController::class, 'storeReply'])->whereNumber('thread')->name('basalto.forum.replies.store');
            // TB 37 (FO-08): Beitrag bearbeiten, mit Verlauf
            Route::post('forum/posts/{post}', [PostingController::class, 'update'])->whereNumber('post')->name('basalto.forum.posts.update');
        });
        // TB 38 (FO-10, FO-12): abonnieren, gelesen, hilfreichste Antwort, abstimmen, Benachrichtigungen
        Route::middleware(['web', 'basalto.site', CustomerAuth::MIDDLEWARE, RequireCommunityTerms::ALIAS])->group(static function (): void {
            Route::post('forum/abo', [ActivityController::class, 'subscribe'])->name('basalto.forum.subscribe');
            Route::post('forum/gelesen', [ActivityController::class, 'markRead'])->name('basalto.forum.read');
            Route::post('forum/threads/{thread}/beste', [ActivityController::class, 'best'])->whereNumber('thread')->name('basalto.forum.best');
            Route::post('forum/polls/{poll}/stimme', [ActivityController::class, 'vote'])->whereNumber('poll')->name('basalto.forum.vote');
            Route::post('forum/benachrichtigungen/gelesen', [ActivityController::class, 'notificationsRead'])->name('basalto.forum.notifications.read');
            // TB 39 (FO-15–17): Moderation an Ort und Stelle — dieselben Aktionen wie im Extranet
            Route::post('forum/moderation/themen', [ModerationController::class, 'threads'])->name('basalto.forum.moderate.threads');
            Route::post('forum/moderation/beitraege', [ModerationController::class, 'posts'])->name('basalto.forum.moderate.posts');
            Route::post('forum/moderation/meldung', [ModerationController::class, 'report'])->name('basalto.forum.moderate.report');
            Route::post('forum/moderation/verwarnen', [ModerationController::class, 'warn'])->name('basalto.forum.moderate.warn');
            Route::post('forum/moderation/spammer', [ModerationController::class, 'spammer'])->name('basalto.forum.moderate.spammer');
        });
        // «Ähnliche Themen» beim Eröffnen — für die Insel am Titel
        Route::middleware(['web', 'basalto.site', 'throttle:60,1'])->get('forum/aehnlich', SimilarController::class)->name('basalto.forum.similar');
        // TB 71: Live-Suche im Suchfeld der Forumsleiste — beim Tippen bis zu fünf Themen
        Route::middleware(['web', 'basalto.site', 'throttle:120,1'])->get('forum/suche-live', LiveSearchController::class)->name('basalto.forum.live-search');
        // TB 37 (FO-06): Zitat eines Beitrags für die Editor-Insel — nur, was die Person lesen darf
        Route::middleware(['web', 'basalto.site'])->get('forum/posts/{post}/zitat', [PostingController::class, 'quote'])->whereNumber('post')->name('basalto.forum.posts.quote');
        // Anhänge an Beiträgen liest, wer das Thema lesen darf
        app(Attachments::class)->readableUsing(Forum::ATTACHMENTS, static fn (CommunityAttachment $attachment, ?Customer $customer): bool => app(PostingController::class)->readable($attachment->subject_id, $customer));

        // TB 38 (FO-13): Kästen als Lava-Teile für jede Region eines Layouts
        $this->app->extend(Registry::class, static function (Registry $registry): Registry {
            $parts = [
                'forum.latest' => ['Letzte Beiträge im Forum', static fn (Widgets $w, Site $site, string $lang, string $base): string => $w->threads($site, CustomerAuth::current(), $lang, $base)],
                'forum.featured' => ['Empfohlene Themen im Forum', static fn (Widgets $w, Site $site, string $lang, string $base): string => $w->featured($site, CustomerAuth::current(), $lang, $base)],
                'forum.hot' => ['Heisse Themen im Forum', static fn (Widgets $w, Site $site, string $lang, string $base): string => $w->threads($site, CustomerAuth::current(), $lang, $base, true)],
                'forum.stats' => ['Statistik des Forums', static fn (Widgets $w, Site $site, string $lang): string => $w->stats($site, $lang)],
                'forum.online' => ['Wer ist online', static fn (Widgets $w, Site $site, string $lang): string => $w->online($site, $lang)],
                // TB 40: Mitgliederliste und Team als Kästen
                'forum.members' => ['Neue Mitglieder', static fn (Widgets $w, Site $site, string $lang, string $base): string => $w->members($site, $lang, $base)],
                'forum.team' => ['Team der Community', static fn (Widgets $w, Site $site, string $lang, string $base): string => $w->team($site, $lang, $base)],
            ];
            $module = $registry->module('forum');
            foreach ($parts as $name => [$label, $render]) {
                $html = static function (string $lang) use ($render): string {
                    $site = app(CurrentSite::class)->get();
                    if ($site === null || ! app(Backend::class)->moduleEnabled('forum', $site)) {
                        return '';
                    }
                    $base = app(Forum::class)->basePath($site, $lang);

                    return $base === null ? '' : '<div class="forum forum--kasten">'.$render(app(Widgets::class), $site, $lang, $base).'</div>'.Styles::tag();
                };
                $module->tag($name, static fn (array $args, array $named, Context $ctx): string => $html((string) ($ctx->get('site')['language'] ?? app()->getLocale())))
                    ->part($name, $label, [], static fn (array $settings, array $vars, string $lang): string => $html($lang));
            }

            return $registry;
        });

        // Datenschutz des Kerns (N-05, FO-23): Auskunft über Themen und Beiträge, beim Löschen bleiben sie anonym stehen
        $this->app->instance('basalto.person-data.forum', (new ForumPersonData)(...));
        $this->app->tag(['basalto.person-data.forum'], 'basalto.person-data');
    }

    /**
     * TB 40 (FO-18, FO-20): Regeln des Forums für Trophäen und Gruppen, Reiter «Beiträge» und «Themen» im Profil.
     */
    private function recognition(): void
    {
        app(CommunityRules::class)
            ->define('forum_threads', 'Eröffnete Themen', 'Mindestens so viele sichtbare Themen im Forum.', static fn (Site $site, int $n): array => CommunityRules::ids(
                Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('is_disabled', false)->whereNull('moved_to_id')->whereNotNull('customer_id')
                    ->groupBy('customer_id')->havingRaw('count(*) >= ?', [max(1, $n)])->pluck('customer_id')->all()))
            ->define('forum_best_answers', 'Hilfreichste Antworten', 'Mindestens so oft wurde eine Antwort der Person als hilfreichste gewählt.', static fn (Site $site, int $n): array => CommunityRules::ids(
                DB::table('forum_threads')->join('forum_posts', 'forum_posts.id', '=', 'forum_threads.best_post_id')->where('forum_threads.site_id', $site->id)
                    ->whereNull('forum_threads.deleted_at')->whereNotNull('forum_posts.customer_id')->groupBy('forum_posts.customer_id')->havingRaw('count(*) >= ?', [max(1, $n)])
                    ->pluck('forum_posts.customer_id')->all()));
        $readable = static fn (Site $site, ?Customer $viewer): array => app(Forum::class)->nodes($site)->filter(static fn (Node $n): bool => app(Forum::class)->can($site, $viewer, Forum::READ, $n))->keys()->all();
        app(ProfileTabs::class)
            ->register('beitraege', 'Beiträge', static fn (Site $site, Customer $owner): int => (int) CommunityProfile::query()->withoutGlobalScopes()->where('customer_id', $owner->id)->value('posts'),
                static fn (Site $site, Customer $owner, ?Customer $viewer, string $lang, string $base): string => ProfileLists::posts($site, $owner, $readable($site, $viewer), $lang, $base))
            ->register('themen', 'Themen', static fn (Site $site, Customer $owner): int => Thread::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('customer_id', $owner->id)
                ->where('is_disabled', false)->whereNull('moved_to_id')->count(),
                static fn (Site $site, Customer $owner, ?Customer $viewer, string $lang, string $base): string => ProfileLists::threads($site, $owner, $readable($site, $viewer), $lang, $base));
    }

    private function backend(string $root): void
    {
        $backend = $this->app->make(Backend::class);
        $backend->module('forum', 'forum::backend.module', switchable: true, composerJson: $root.'/composer.json', enabledByDefault: false);
        $backend->pages('forum', $root.'/resources/js/pages');
        $registry = $this->app->make(ExtranetRegistry::class);
        $registry->menu('Community', 'basalto.forum.index', 45, 'community', 'comments');
        // TB 55: Statistik — Mitglieder, Themen, Beiträge
        $registry->statistics('community', 'Community', 'community', 50, CommunityStatistics::section(...));
        // TB 39: Meldungen und Freischaltungen als «Heute zu tun»
        $reports = static fn (): int => app(Reports::class)->pendingCount((int) Extranet::vendorSite()?->id, [Moderation::REPORT_TYPE]);
        $pending = static fn (): int => Post::query()->withoutGlobalScope(SiteScope::class)->where('site_id', Extranet::vendorSite()?->id)->where('is_disabled', true)->count();
        $registry->count('basalto.forum.index', static fn (): int => $reports() + $pending());
        $registry->todo('community_reports', 'Gemeldete Beiträge im Forum', 'community', $reports, static fn (): string => route('basalto.forum.moderation', ['tab' => 'meldungen']));
        $registry->todo('community_pending', 'Beiträge warten auf Freischaltung', 'community', $pending, static fn (): string => route('basalto.forum.moderation', ['tab' => 'freischaltung']));
        $registry->routes(static function (): void {
            Route::name('basalto.')->group(static fn () => self::adminRoutes('forum.'));
        });
        // TB 41 (FO-28): dasselbe im Backend jeder Site unter Verwaltung › Community — nicht auf der Hersteller-Site,
        // deren Community das Extranet verwaltet
        app(Areas::class)->register('forumadmin', 'Community', Areas::ADMIN, 'forum')->map(ForumRoutes::BACKEND, 'forumadmin');
        $backend->routes(static fn () => self::adminRoutes('forumadmin.'));
        $backend->menu('Community', ForumRoutes::BACKEND.'index', 64, Backend::MODE_ADMIN, module: 'forum', icon: 'comments');
        $backend->visibleWhen(ForumRoutes::BACKEND.'index', static fn (?Authenticatable $user, ?Site $site): bool => $site === null || ! Extranet::enabled() || $site->id !== Extranet::vendorSite()?->id);
    }

    /**
     * Die Verwaltung des Forums (CR-006 § 6) — einmal im Extranet (`basalto.forum.*`), einmal im Backend der Kunden-Sites
     * (`basalto.forumadmin.*`, FO-28). Dieselben Controller, dieselben Seiten.
     */
    public static function adminRoutes(string $p): void
    {
        Route::get('community', [CommunityController::class, 'index'])->name($p.'index');
        Route::post('community/team', [CommunityController::class, 'link'])->name($p.'team.link');
        Route::delete('community/team', [CommunityController::class, 'unlink'])->name($p.'team.unlink');
        Route::post('community/seite', [CommunityController::class, 'createPage'])->name($p.'page');
        Route::post('community/einstellungen', [CommunityController::class, 'settings'])->name($p.'settings');
        Route::get('community/foren', [NodeController::class, 'index'])->name($p.'nodes');
        Route::post('community/praefixe', [PrefixController::class, 'store'])->name($p.'prefixes.store');
        Route::put('community/praefixe/{prefix}', [PrefixController::class, 'update'])->whereNumber('prefix')->name($p.'prefixes.update');
        Route::delete('community/praefixe/{prefix}', [PrefixController::class, 'destroy'])->whereNumber('prefix')->name($p.'prefixes.destroy');
        Route::post('community/foren', [NodeController::class, 'store'])->name($p.'nodes.store');
        Route::put('community/foren/{node}', [NodeController::class, 'update'])->whereNumber('node')->name($p.'nodes.update');
        Route::post('community/foren/{node}/verschieben', [NodeController::class, 'move'])->whereNumber('node')->name($p.'nodes.move');
        Route::delete('community/foren/{node}', [NodeController::class, 'destroy'])->whereNumber('node')->name($p.'nodes.destroy');
        Route::get('community/gruppen', [GroupController::class, 'index'])->name($p.'groups');
        Route::post('community/gruppen', [GroupController::class, 'store'])->name($p.'groups.store');
        Route::put('community/gruppen/{group}', [GroupController::class, 'update'])->whereNumber('group')->name($p.'groups.update');
        Route::delete('community/gruppen/{group}', [GroupController::class, 'destroy'])->whereNumber('group')->name($p.'groups.destroy');
        Route::post('community/gruppen/{group}/mitglieder', [GroupController::class, 'addMember'])->whereNumber('group')->name($p.'groups.members.add');
        Route::delete('community/gruppen/{group}/mitglieder/{customer}', [GroupController::class, 'removeMember'])->whereNumber(['group', 'customer'])->name($p.'groups.members.remove');
        Route::post('community/rechte', [GroupController::class, 'permission'])->name($p.'permissions');
        Route::get('community/konten', [GroupController::class, 'customers'])->name($p.'customers');
        // TB 39: Moderation (Meldungen, Freischaltung, Papierkorb, Protokoll), Mitglieder mit Verwarnungen und Sperren, Hinweise
        Route::get('community/moderation', [ExtranetModeration::class, 'index'])->name($p.'moderation');
        Route::post('community/moderation/themen', [ExtranetModeration::class, 'threads'])->name($p.'moderation.threads');
        Route::post('community/moderation/beitraege', [ExtranetModeration::class, 'posts'])->name($p.'moderation.posts');
        Route::post('community/moderation/meldung', [ExtranetModeration::class, 'report'])->name($p.'moderation.report');
        Route::get('community/mitglieder', [MemberController::class, 'index'])->name($p.'members');
        Route::post('community/mitglieder/{customer}/verwarnen', [MemberController::class, 'warn'])->whereNumber('customer')->name($p.'members.warn');
        Route::post('community/mitglieder/{customer}/sperre', [MemberController::class, 'sanction'])->whereNumber('customer')->name($p.'members.sanction');
        Route::post('community/mitglieder/{customer}/spammer', [MemberController::class, 'spammer'])->whereNumber('customer')->name($p.'members.spammer');
        Route::delete('community/verwarnungen/{warning}', [MemberController::class, 'revoke'])->whereNumber('warning')->name($p.'warnings.revoke');
        // TB 40: Profile und Auszeichnungen — Ränge, Trophäen, Gruppenregeln, Profilfelder
        Route::get('community/auszeichnungen', [RecognitionController::class, 'index'])->name($p.'recognition');
        Route::get('community/auszeichnungen/vorschau', [RecognitionController::class, 'preview'])->name($p.'recognition.preview');
        Route::post('community/auszeichnungen/anwenden', [RecognitionController::class, 'run'])->name($p.'recognition.run');
        Route::put('community/profilfelder', [RecognitionController::class, 'fields'])->name($p.'profile-fields');
        Route::post('community/raenge', [RecognitionController::class, 'storeRank'])->name($p.'ranks.store');
        Route::put('community/raenge/{rank}', [RecognitionController::class, 'updateRank'])->whereNumber('rank')->name($p.'ranks.update');
        Route::delete('community/raenge/{rank}', [RecognitionController::class, 'destroyRank'])->whereNumber('rank')->name($p.'ranks.destroy');
        Route::post('community/trophaeen', [RecognitionController::class, 'storeTrophy'])->name($p.'trophies.store');
        Route::put('community/trophaeen/{trophy}', [RecognitionController::class, 'updateTrophy'])->whereNumber('trophy')->name($p.'trophies.update');
        Route::delete('community/trophaeen/{trophy}', [RecognitionController::class, 'destroyTrophy'])->whereNumber('trophy')->name($p.'trophies.destroy');
        Route::post('community/trophaeen/{trophy}/vergeben', [RecognitionController::class, 'award'])->whereNumber('trophy')->name($p.'trophies.award');
        Route::post('community/gruppenregeln', [RecognitionController::class, 'storeGroupRule'])->name($p.'group-rules.store');
        Route::put('community/gruppenregeln/{rule}', [RecognitionController::class, 'updateGroupRule'])->whereNumber('rule')->name($p.'group-rules.update');
        Route::delete('community/gruppenregeln/{rule}', [RecognitionController::class, 'destroyGroupRule'])->whereNumber('rule')->name($p.'group-rules.destroy');
        Route::get('community/hinweise', [NoticeController::class, 'index'])->name($p.'notices');
        Route::post('community/hinweise', [NoticeController::class, 'store'])->name($p.'notices.store');
        Route::put('community/hinweise/{notice}', [NoticeController::class, 'update'])->whereNumber('notice')->name($p.'notices.update');
        Route::delete('community/hinweise/{notice}', [NoticeController::class, 'destroy'])->whereNumber('notice')->name($p.'notices.destroy');
    }
}
