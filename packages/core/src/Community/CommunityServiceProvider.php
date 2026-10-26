<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Community\Http\AttachmentController;
use Basalto\Core\Community\Http\PreviewController;
use Basalto\Core\Community\Http\ProfileController;
use Basalto\Core\Community\Http\ReactionController;
use Basalto\Core\Community\Http\ReportController;
use Basalto\Core\Community\Http\SocialController;
use Basalto\Core\Community\Http\TermsController;
use Basalto\Core\Community\Http\TypingController;
use Basalto\Core\Community\Http\WallController;
use Basalto\Core\Console\CommunityDigestCommand;
use Basalto\Core\Console\CommunityRulesCommand;
use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\FrontendAccounts\CustomerAuth;
use Basalto\Core\Http\Middleware\RequireCommunityTerms;
use Basalto\Core\Live\LiveSources;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\NotificationType;
use Basalto\Core\Notifications\NotificationTypes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bausteine «Community» im Kern (CR-006, TB 36): Einhängeseiten (FO-K1), Community-Profil (FO-K2), Besuchergruppen und
 * -rechte (FO-K3), Team-Verknüpfung (FO-K14). Module wie das Forum bauen darauf; ohne ein solches Modul bleibt alles
 * still — die Routen antworten nur, wenn die Site ein Frontend-Konto hat.
 */
final class CommunityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Mounts::class);
        $this->app->singleton(VisitorPermissions::class);
        $this->app->singleton(Attachments::class);
        $this->app->singleton(Subjects::class);
        // TB 39 (FO-K9): Meldungen und Protokoll — Definitionen aus dem Booten der Module
        $this->app->singleton(Reports::class);
        $this->app->singleton(ModerationLog::class);
        // TB 40: Regeln und Profil-Reiter aus den Modulen
        $this->app->singleton(CommunityRules::class);
        $this->app->singleton(ProfileTabs::class);
        // Je Anfrage neu (Octane): Gruppen und gesetzte Rechte werden einmal geladen
        $this->app->scoped(VisitorGroups::class);
        $this->app->scoped(VisitorAccess::class);
        $this->app->scoped(Sanctions::class);
        // CR-011 TB 60: Zustimmung zu den Bedingungen — je Anfrage einmal nachgeschlagen
        $this->app->scoped(CommunityTerms::class);
    }

    public function boot(): void
    {
        // CR-008: Zähler im Kopf des Forums live — ungelesene Benachrichtigungen und Nachrichten eines Kundenkontos
        app(LiveSources::class)->register('konto', static function (int $siteId, string $key): ?array {
            $customer = Customer::query()->withoutGlobalScopes()->where('site_id', $siteId)->find((int) substr($key, strlen('konto.')));

            if ($customer === null) {
                return null;
            }
            $notifications = app(CommunityNotifications::class);
            // Die neueste ungelesene — für die Desktop-Benachrichtigung (Titel in der Sprache des Kontos)
            $newest = $notifications->latest($customer, 5)->first(static fn (DatabaseNotification $row): bool => $row->getAttribute('read_at') === null);
            $site = $newest === null ? null : Site::query()->find($siteId);
            $base = $site === null ? null : app(Mounts::class)->baseFor($site, $customer->locale ?: null);

            return ['benachrichtigungen' => $notifications->unread($customer), 'nachrichten' => count(app(Conversations::class)->unreadIds($customer)),
                'neueste' => $newest === null || $base === null ? null : ['id' => (string) $newest->id, 'titel' => CommunityNotifications::title($newest, (string) ($customer->locale ?: 'de')),
                    'url' => $base.'/benachrichtigungen/'.$newest->id]];
        });
        Route::middleware(['web', 'basalto.site', CustomerAuth::MIDDLEWARE])
            ->post('community/profile', [ProfileController::class, 'update'])->name('basalto.community.profile');
        // TB 37 (FO-07): Anhänge — hochladen aus der Insel, wieder entfernen, ausliefern mit Leserecht
        RateLimiter::for('basalto-community-upload', static fn (Request $request): Limit => Limit::perMinute(20)->by('upload|'.(CustomerAuth::current()->id ?? $request->ip())));
        $this->app->make(Router::class)->aliasMiddleware(RequireCommunityTerms::ALIAS, RequireCommunityTerms::class);
        // CR-011 TB 60: den Bedingungen zustimmen (Konto mit CSRF, Gast ohne Skript als Cookie) und im Kundenkonto widerrufen
        Route::middleware(['web', 'basalto.site', CustomerAuth::MIDDLEWARE])->group(static function (): void {
            Route::post('community/bedingungen', [TermsController::class, 'accept'])->name('basalto.community.terms.accept');
            Route::post('community/bedingungen/widerrufen', [TermsController::class, 'revoke'])->name('basalto.community.terms.revoke');
        });
        Route::middleware(['web', 'basalto.site', 'throttle:30,1'])->withoutMiddleware(PreventRequestForgery::class)
            ->post('community/bedingungen/gast', [TermsController::class, 'guest'])->name('basalto.community.terms.guest');
        EncryptCookies::except(CommunityTerms::COOKIE);
        Route::middleware(['web', 'basalto.site', CustomerAuth::MIDDLEWARE, RequireCommunityTerms::ALIAS])->group(static function (): void {
            Route::post('community/anhaenge', [AttachmentController::class, 'store'])->middleware('throttle:basalto-community-upload')->name('basalto.community.attachments.store');
            // Erwähnungen (@Name) in der Editor-Insel: passende Anzeigenamen
            // TB 38 (FO-K8): Reaktionen
            Route::post('community/reaktionen', ReactionController::class)->middleware('throttle:basalto-community-upload')->name('basalto.community.react');
            Route::get('community/namen', [ProfileController::class, 'search'])->middleware('throttle:basalto-community-upload')->name('basalto.community.names');
            // TB 39 (FO-14, FO-17): melden, Hinweis ausblenden
            Route::post('community/melden', [ReportController::class, 'store'])->middleware('throttle:basalto-community-upload')->name('basalto.community.report');
            Route::post('community/hinweise/{notice}/ausblenden', [ReportController::class, 'dismissNotice'])->whereNumber('notice')->name('basalto.community.notices.dismiss');
            // TB 40 (FO-18, FO-19): folgen, blockieren, Konversationen
            Route::post('community/folgen', [SocialController::class, 'follow'])->name('basalto.community.follow');
            Route::post('community/blockieren', [SocialController::class, 'block'])->name('basalto.community.block');
            Route::post('community/konversationen', [SocialController::class, 'start'])->middleware('throttle:basalto-community-upload')->name('basalto.community.conversations.start');
            Route::post('community/konversationen/{conversation}/antworten', [SocialController::class, 'reply'])->whereNumber('conversation')->middleware('throttle:basalto-community-upload')->name('basalto.community.conversations.reply');
            Route::post('community/konversationen/{conversation}/einladen', [SocialController::class, 'invite'])->whereNumber('conversation')->name('basalto.community.conversations.invite');
            Route::post('community/konversationen/{conversation}/verlassen', [SocialController::class, 'leave'])->whereNumber('conversation')->name('basalto.community.conversations.leave');
            Route::delete('community/anhaenge/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment')->name('basalto.community.attachments.destroy');
            // TB 49: «Wer schreibt gerade» und die Vorschau der Editor-Insel
            Route::post('community/schreibt', TypingController::class)->middleware('throttle:basalto-community-typing')->name('basalto.community.typing');
            Route::post('community/vorschau', PreviewController::class)->middleware('throttle:basalto-community-upload')->name('basalto.community.preview');
            // TB 52: Pinnwand im Profil
            Route::post('community/pinnwand/{profile}', [WallController::class, 'store'])->whereNumber('profile')->middleware('throttle:basalto-community-upload')->name('basalto.community.wall.store');
            Route::post('community/pinnwand/eintrag/{post}/loeschen', [WallController::class, 'destroy'])->whereNumber('post')->name('basalto.community.wall.destroy');
        });
        RateLimiter::for('basalto-community-typing', static fn (Request $request): Limit => Limit::perMinute(40)->by('schreibt|'.(CustomerAuth::current()->id ?? $request->ip())));
        // Wer gerade tippt — Nutzdaten zum Live-Schlüssel `schreibt.…`
        app(LiveSources::class)->register(Typing::PREFIX, static fn (int $siteId, string $key): array => ['schreibt' => app(Typing::class)->current($siteId, $key)]);
        Route::middleware(['web', 'basalto.site'])->get('community/anhang/{attachment}/{file}', [AttachmentController::class, 'show'])
            ->whereNumber('attachment')->where('file', '[a-z0-9]{16}\.[a-z0-9]{2,5}')->name('basalto.community.attachment');
        app(VisitorPermissions::class)->define(Attachments::PERMISSION, 'Dateien anhängen', 'Community', [VisitorGroup::MEMBERS => VisitorPermission::YES], writes: true)
            ->define(Flood::PERMISSION, 'Ohne Wartezeit schreiben', 'Community', [VisitorGroup::MODERATORS => VisitorPermission::YES, VisitorGroup::TEAM => VisitorPermission::YES],
                help: 'Gilt nicht für den Mindestabstand zwischen zwei Beiträgen (Flood-Control).')
            // TB 52 (CR-009 Nr. 24): ganze Gruppen erwähnen
            ->define(VisitorGroups::MENTION_PERMISSION, 'Gruppen erwähnen', 'Community', [VisitorGroup::MODERATORS => VisitorPermission::YES, VisitorGroup::TEAM => VisitorPermission::YES],
                help: 'Mit @ und dem Namen einer Gruppe (Team, Moderation, eigene Gruppen) — alle ihre Mitglieder bekommen eine Benachrichtigung (höchstens 100).');
        // TB 39 (FO-17): die verwarnte Person erfährt es in der Glocke und per E-Mail
        app(NotificationTypes::class)->type(Warnings::NOTIFICATION, 'Verwarnung', 'Community', scope: NotificationType::FRONTEND, mail: true, icon: 'comments');
        // TB 38 (FO-11): tägliche Zusammenfassung für Konten, die sie gewählt haben
        if ($this->app->runningInConsole()) {
            $this->commands([CommunityDigestCommand::class, CommunityRulesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('basalto:community-digest')->dailyAt('07:00')->withoutOverlapping();
            $schedule->command('basalto:community-rules')->dailyAt('03:30')->withoutOverlapping();
        });
        $this->app->instance('basalto.cleanup.community', (new CommunityCleanup)(...));
        $this->app->tag(['basalto.cleanup.community'], 'basalto.cleanup');

        Route::middleware(['basalto.site'])->get('community/avatar/{profile}/{file}', [ProfileController::class, 'avatar'])
            ->whereNumber('profile')->where('file', '[a-z0-9]{16}\.webp')->name('basalto.community.avatar');
        Route::middleware(['basalto.site'])->get('community/cover/{profile}/{file}', [ProfileController::class, 'cover'])
            ->whereNumber('profile')->where('file', '[a-z0-9]{16}\.webp')->name('basalto.community.cover');

        // TB 40 (FO-19): Konversationen — Nachricht und Trophäe in Glocke und E-Mail; gemeldet wird eine einzelne Nachricht
        app(NotificationTypes::class)->type(Conversations::NOTIFICATION, 'Neue Nachricht in einer Konversation', 'Community', scope: NotificationType::FRONTEND, mail: true, icon: 'comments')
            ->type(Recognition::NOTIFICATION, 'Neue Trophäe', 'Community', scope: NotificationType::FRONTEND, icon: 'check');
        $message = static fn (int $id): ?ConversationMessage => ConversationMessage::query()->withoutGlobalScopes()->find($id);
        app(Subjects::class)->register(Conversations::REPORT_TYPE,
            static function (int $id, ?Customer $customer) use ($message): bool {
                $row = $message($id);
                $conversation = $row === null ? null : Conversation::query()->withoutGlobalScopes()->find($row->conversation_id);

                return $conversation !== null && $row->removed_at === null && app(Conversations::class)->canRead($conversation, $customer);
            },
            static fn (int $id): ?int => $message($id)?->customer_id);
        app(Reports::class)->define(Conversations::REPORT_TYPE, 'Nachricht in einer Konversation',
            static function (int $id) use ($message): ?array {
                $row = $message($id);
                $conversation = $row === null ? null : Conversation::query()->withoutGlobalScopes()->find($row->conversation_id);

                return $row === null || $conversation === null ? null : ['title' => $conversation->subject, 'excerpt' => $row->removed_at !== null ? '' : mb_substr(RichText::toText($row->body ?? []), 0, 240),
                    'url' => null, 'author' => $row->customer_id, 'place' => (string) __('Konversation')];
            });

        // TB 52: Pinnwand — Benachrichtigung an die Person und Einträge melden
        app(NotificationTypes::class)->type(Wall::NOTIFICATION, 'Neuer Eintrag auf der Pinnwand', 'Community', scope: NotificationType::FRONTEND, mail: true, icon: 'comments');
        $wallPost = static fn (int $id): ?CommunityWallPost => CommunityWallPost::query()->withoutGlobalScopes()->find($id);
        app(Subjects::class)->register(Wall::REPORT_TYPE, static fn (int $id, ?Customer $customer): bool => $wallPost($id) !== null, static fn (int $id): ?int => $wallPost($id)?->customer_id);
        app(Reports::class)->define(Wall::REPORT_TYPE, 'Eintrag auf einer Pinnwand',
            static function (int $id) use ($wallPost): ?array {
                $row = $wallPost($id);
                $owner = $row === null ? null : CommunityProfile::query()->withoutGlobalScopes()->find($row->profile_id);
                $site = $owner === null ? null : Site::query()->find($owner->site_id);

                return $row === null || $owner === null ? null : ['title' => (string) __('Pinnwand von :name', ['name' => $owner->name]), 'excerpt' => mb_substr(RichText::toText($row->body), 0, 240),
                    'url' => $site === null ? null : app(Wall::class)->url($site, $owner, $row), 'author' => $row->customer_id, 'place' => (string) __('Pinnwand')];
            },
            static function (int $id) use ($wallPost): void {
                $wallPost($id)?->delete();
            });

        // Datenschutz (N-05): Profil und Gruppen je E-Mail — vor dem Konto (das ändert die E-Mail zuletzt)
        $this->app->instance('basalto.person-data.community', (new CommunityPersonData)(...));
        $this->app->tag(['basalto.person-data.community'], 'basalto.person-data');
    }
}
