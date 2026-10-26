<?php

declare(strict_types=1);

namespace Basalto\Core;

use Basalto\Core\Access\Access;
use Basalto\Core\Access\Areas;
use Basalto\Core\Access\CoreAreas;
use Basalto\Core\Access\Level;
use Basalto\Core\Activity\Recorder;
use Basalto\Core\Analytics\Statistics;
use Basalto\Core\Analytics\TrackPageView;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Cache\PageCacheInvalidation;
use Basalto\Core\Cloud\CloudCli;
use Basalto\Core\Cloud\CommandRunner;
use Basalto\Core\Cloud\ProcessRunner;
use Basalto\Core\Community\CommunityServiceProvider;
use Basalto\Core\Console\BackupCommand;
use Basalto\Core\Console\BackupVerifyCommand;
use Basalto\Core\Console\BenchmarkCommand;
use Basalto\Core\Console\CancellationsCommand;
use Basalto\Core\Console\CleanupCommand;
use Basalto\Core\Console\DatabaseTimezoneCommand;
use Basalto\Core\Console\DemoResetCommand;
use Basalto\Core\Console\DocsImportCommand;
use Basalto\Core\Console\ErrorAlarmCommand;
use Basalto\Core\Console\ExportCommand;
use Basalto\Core\Console\HeartbeatCommand;
use Basalto\Core\Console\ImportCommand;
use Basalto\Core\Console\InstallCommand;
use Basalto\Core\Console\LegalCommand;
use Basalto\Core\Console\LicenseCommand;
use Basalto\Core\Console\LoadTestCommand;
use Basalto\Core\Console\LocalCommand;
use Basalto\Core\Console\LoginLinkCommand;
use Basalto\Core\Console\PageCacheCommand;
use Basalto\Core\Console\PrivacyCommand;
use Basalto\Core\Console\PublishCommand;
use Basalto\Core\Console\PublishScheduledCommand;
use Basalto\Core\Console\PurgeMediaCommand;
use Basalto\Core\Console\ReleaseCommand;
use Basalto\Core\Console\SearchIndexCommand;
use Basalto\Core\Console\SeedCommand;
use Basalto\Core\Console\TestdataCommand;
use Basalto\Core\Console\TestingCommand;
use Basalto\Core\Console\ThemePackCommand;
use Basalto\Core\Console\ThemeVerifyCommand;
use Basalto\Core\Console\TokensCommand;
use Basalto\Core\Console\UpdateCommand;
use Basalto\Core\Console\VerifyDomainsCommand;
use Basalto\Core\Content\ContentTypes;
use Basalto\Core\Content\Pages;
use Basalto\Core\Demo\DemoMode;
use Basalto\Core\Events\EntrySaved;
use Basalto\Core\Events\VersionTransitioned;
use Basalto\Core\Extranet\CoreExtranet;
use Basalto\Core\Extranet\Extranet;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Extranet\ExtranetRegistry;
use Basalto\Core\FrontendAccounts\FrontendAccountsServiceProvider;
use Basalto\Core\Http\Middleware\KeepOutOfSearchEngines;
use Basalto\Core\Http\Middleware\ResetRequestState;
use Basalto\Core\Http\Middleware\ResolveSiteFromHost;
use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Http\Middleware\ShowLicenseBanner;
use Basalto\Core\Legal\Cancellation\ContractSources;
use Basalto\Core\Legal\Cancellation\SaasContractSource;
use Basalto\Core\Listeners\RecordActivity;
use Basalto\Core\Listeners\SyncMediaUsages;
use Basalto\Core\Live\Live;
use Basalto\Core\Live\LivePush;
use Basalto\Core\Live\LiveSources;
use Basalto\Core\Live\Presence;
use Basalto\Core\Mail\MailBrand;
use Basalto\Core\Mail\UndeliverableRecipients;
use Basalto\Core\Models\ApiToken;
use Basalto\Core\Models\Entry;
use Basalto\Core\Models\Media;
use Basalto\Core\Models\PrivacyRequest;
use Basalto\Core\Models\SearchDocument;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\CoreNotifications;
use Basalto\Core\Notifications\NotificationTypes;
use Basalto\Core\Operations\Health;
use Basalto\Core\Operations\MonitoringSetup;
use Basalto\Core\Policies\EntryPolicy;
use Basalto\Core\Policies\MediaPolicy;
use Basalto\Core\Policies\SitePolicy;
use Basalto\Core\Privacy\PersonScope;
use Basalto\Core\Saas\Offboarding\OffboardingServiceProvider;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Saas\SaasServiceProvider;
use Basalto\Core\Search\SearchSources;
use Basalto\Core\Search\SearchSync;
use Basalto\Core\Settings\Settings;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sso\SsoServiceProvider;
use Basalto\Core\Support\SourceLocaleTranslator;
use Basalto\Core\Testdata\CoreTestdata;
use Basalto\Core\Testdata\Testdata;
use Basalto\Core\Testdata\TestdataRun;
use Basalto\Core\Theme\SiteThemeSource;
use Basalto\Core\Theme\ThemeLoader;
use Basalto\Core\Updates\Installation;
use Basalto\Core\Updates\UpdateCheck;
use Basalto\Lava\Theme\SiteSourceResolver;
use Basalto\Lava\Theme\ThemeImporter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\View\FileViewFinder;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\Sanctum;

/**
 * Der Kern als Composer-Paket: Konfiguration, Routen, Views, Übersetzungen, Migrationen und die fertig
 * gebauten Backend-Assets (resources/dist → public/vendor/basalto/core). Wird per Paket-Discovery geladen.
 */
final class CoreServiceProvider extends ServiceProvider
{
    private string $root;

    public function register(): void
    {
        $this->app->scoped(MailBrand::class);
        // CR-011 TB 61: Auskunft und Löschen aus dem Kundenkonto nur für die eigene Site
        $this->app->scoped(PersonScope::class);
        $this->root = dirname(__DIR__);
        $this->mergeConfigFrom($this->root.'/config/basalto.php', 'basalto');
        // Mehrzahl auf Deutsch aus dem Schlüssel statt aus der Ersatzsprache (siehe SourceLocaleTranslator)
        $this->app->extend('translator', static fn (Translator $translator): Translator => $translator instanceof SourceLocaleTranslator ? $translator : SourceLocaleTranslator::from($translator));
        // CR-005: Verzeichnis der Bereiche (für die Laufzeit der App) und Prüfstelle der Rechte (je Anfrage neu)
        $this->app->singleton(Areas::class);
        $this->app->scoped(Access::class);
        // CR-006 (TB 41, FO-K12): Quellen der Suche aus den Modulen
        $this->app->singleton(SearchSources::class);
        // CR-011 TB 59: kündbare Verträge aus Kern und Modulen für «Verträge hier kündigen»
        $this->app->singleton(ContractSources::class);
        // Testdaten aus Kern und Modulen (basalto:testdaten)
        $this->app->singleton(Testdata::class);
        // CR-007: die Installation, die sich aktualisiert — immer die laufende
        $this->app->bind(Installation::class, static fn (): Installation => Installation::current());
        // TB 19 (CR-004): Hersteller-Extranet — Register der Module und Rechte des Teams (je Anfrage neu)
        $this->app->singleton(ExtranetRegistry::class);
        $this->app->scoped(ExtranetAccess::class);
        // TB 27: Verzeichnis der Benachrichtigungen — Kern und Module melden ihre Arten an
        $this->app->singleton(NotificationTypes::class);
        // CR-008: Live-Aktualisierung — Quellen aus Kern und Modulen, Push nur, wo eingerichtet
        $this->app->singleton(LiveSources::class);
        $this->app->singleton(LivePush::class);
        $this->app->singleton(Live::class);
        // Die Vorgabesprache festhalten: app()->setLocale() überschreibt «app.locale», ResetRequestState braucht den Startwert (N-01)
        config()->set('basalto.default_locale', (string) config('app.locale', 'de'));
        $this->app->extend('translation.loader', fn (FileLoader $loader, $app): FileLoader => new FileLoader($app['files'], [$this->root.'/lang', ...$loader->paths()]));
        $this->app->singleton(Backend::class);
        $this->app->singleton(CurrentSite::class);
        $this->app->singleton(ThemeLoader::class);
        $this->app->singleton(SiteSourceResolver::class, SiteThemeSource::class);
        $this->app->singleton(ThemeImporter::class, SiteThemeSource::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(Pages::class);
        $this->app->singleton(ContentTypes::class);
        $this->app->bind(CommandRunner::class, ProcessRunner::class);
        $this->app->singleton(CloudCli::class, static fn ($app): CloudCli => CloudCli::fromConfig($app->make(CommandRunner::class)));
        // TB 12 Scheibe 1 (M-21): SaaS-Modus — Registrierung, Konto-Bereich, Testphase (ohne BASALTO_SAAS alles 404)
        $this->app->register(SaasServiceProvider::class);
        // TB 13 Scheibe 4 (M-25): SSO für Backend-Benutzer — Entra ID und Google je Site
        $this->app->register(SsoServiceProvider::class);
        // TB 12 Scheibe 4 (M-21, ADR-008): Export, Kündigung, Sperre, Löschung, Umzug zwischen Instanzen
        $this->app->register(OffboardingServiceProvider::class);
        // TB 13 Scheibe 2 (M-23): Frontend-Konto im Kern (aus dem Lizenzverkauf gehoben) — für Lizenzverkauf, Marktplatz, Mitglieder
        $this->app->register(FrontendAccountsServiceProvider::class);
        // CR-006 (TB 36): Bausteine «Community» — Einhängeseiten, Profil, Besuchergruppen und -rechte
        $this->app->register(CommunityServiceProvider::class);
    }

    public function boot(): void
    {
        // TB 49: wer einen Inhalt im Backend gerade bearbeitet — Nutzdaten zum Live-Schlüssel `bearbeitet.…`
        $this->app->make(LiveSources::class)->register('bearbeitet', static fn (int $siteId, string $key): array => ['personen' => app(Presence::class)->current($siteId, $key)]);
        // Fliesstext: Text-Knoten im ProseMirror-JSON nicht kürzen — sonst verschwinden Leerzeichen neben Formatierung
        // («Produkte **und** Projekte» → «Produkte**und**Projekte»), gefunden mit dem Autosave
        TrimStrings::except(['*content.*.text', 'lava_source']);
        $this->loadViewsFrom($this->root.'/resources/views', 'basalto');
        // CR-011 (TB 58): eigenes Mail-Layout — Kopf und Fuss mit der Site des Empfängers, Gruss mit ihrem Namen, Basalt und Lava
        config(['mail.markdown.paths' => array_values(array_unique([$this->root.'/resources/views/mail', ...(array) config('mail.markdown.paths', [])]))]);
        if (config('mail.markdown.theme', 'default') === 'default') {
            config(['mail.markdown.theme' => 'basalto']);
        }
        View::prependNamespace('notifications', $this->root.'/resources/views/notifications');
        Event::listen(NotificationSending::class, static function (NotificationSending $event): void {
            if ($event->channel === 'mail') {
                app(MailBrand::class)->useFor($event->notifiable);
            }
        });
        Event::listen(NotificationSent::class, static fn () => app(MailBrand::class)->reset());
        $this->loadTranslationsFrom($this->root.'/lang', 'basalto');
        // TB 10 Scheibe 5 (N-01): Oberfläche EN/IT/FR — der deutsche Text ist der Schlüssel (lang/<sprache>.json, auch im Browser)
        $this->loadJsonTranslationsFrom($this->root.'/lang');
        $this->loadMigrationsFrom($this->root.'/database/migrations');
        $this->loadRoutesFrom($this->root.'/routes/backend.php');
        $this->loadRoutesFrom($this->root.'/routes/frontend.php');
        $this->loadRoutesFrom($this->root.'/routes/api.php');

        $this->publishes([$this->root.'/resources/dist' => public_path('vendor/basalto/core')], ['basalto-assets', 'laravel-assets']);
        // Bilder der Demo-Startseite (Foto mit Schema, tools/landing-art.py) — mit den Assets, nicht im Starter-Theme
        $this->publishes([$this->root.'/resources/demo/assets' => public_path('vendor/basalto/core/demo')], ['basalto-assets', 'laravel-assets']);
        $this->publishes([$this->root.'/config/basalto.php' => config_path('basalto.php')], 'basalto-config');

        CoreAreas::register($this->app->make(Areas::class));
        if (Extranet::enabled()) {
            CoreExtranet::register($this->app->make(Areas::class), $this->app->make(ExtranetRegistry::class));
            // Nach allen Modulen laden: sie hängen ihre Routen in boot() ins Register
            $this->app->booted(function (): void {
                if (! $this->app->routesAreCached()) {
                    require $this->root.'/routes/extranet.php';
                }
            });
        }
        Gate::policy(Entry::class, EntryPolicy::class);
        // CR-005: ausdrückliche Prüfung «Bereich und Stufe» für neuen Code — Gate::allows('area', [$site, 'design', Level::Edit])
        Gate::define('area', static fn (Authenticatable $user, Site $site, string $area, Level $level = Level::View): bool => app(Access::class)->allows($user, $site, $area, $level));
        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        // Domänenereignisse des Kerns (ADR-011) → Protokoll (K-15)
        Event::subscribe(RecordActivity::class);
        Event::listen(EntrySaved::class, SyncMediaUsages::class);
        // Zahlen an «Freigabe» und «Übersetzung» nach jeder Änderung sofort neu (sonst bis 30 s veraltet)
        $forgetCounts = static fn (int $siteId) => Cache::forget('basalto.menu-counts.'.$siteId);
        Event::listen(EntrySaved::class, static fn (EntrySaved $e) => $forgetCounts($e->entry->site_id));
        Event::listen(VersionTransitioned::class, static fn (VersionTransitioned $e) => $forgetCounts($e->version->entry->site_id));
        // TB 27: Benachrichtigungen des Kerns (Workflow, Sicherheit, Extranet)
        CoreNotifications::register($this->app->make(NotificationTypes::class));
        PageCacheInvalidation::register();
        SearchSync::register();
        // Meilisearch filtert nur, was vorher als filterbar gemeldet ist — der Treiberwechsel bleibt so reine Konfiguration
        if (config('scout.meilisearch.index-settings.'.SearchDocument::class) === null) {
            config()->set('scout.meilisearch.index-settings.'.SearchDocument::class, SearchDocument::INDEX_SETTINGS);
        }
        // K-04: fünf Code-Anfragen oder -Prüfungen je Minute je Adresse und IP
        RateLimiter::for('basalto-login', static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email', ''))));
        // Code-Prüfung: der Code selbst sperrt nach fünf Fehlversuchen; das Limit hält nur Massenanfragen fern
        // CR-008: Abfrage alle 15 s aus vielen Tabs (auch hinter einer gemeinsamen Adresse) — grosszügig, aber begrenzt
        RateLimiter::for('basalto-live', static fn (Request $request): Limit => Limit::perMinute(max(60, (int) config('basalto.live.rate', 600)))->by('live|'.$request->ip()));
        RateLimiter::for('basalto-login-verify', static fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));
        // Installer im Browser (K-05): eigener Zähler, damit er keine fremde Grenze erbt
        RateLimiter::for('basalto-install', static fn (Request $request): Limit => Limit::perMinute(30)->by('install|'.$request->ip()));
        // M-17: REST-API je Token (Site aus dem Token) — eigener Zähler, Grenze aus `basalto.api.per_minute`
        RateLimiter::for('basalto-api', static function (Request $request): Limit {
            $token = $request->user() instanceof HasApiTokens ? $request->user()->currentAccessToken() : null;

            return Limit::perMinute((int) config('basalto.api.per_minute', 120))->by($token instanceof ApiToken ? 'api|token:'.$token->id : 'api|ip:'.$request->ip());
        });
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        $this->app->make(Router::class)->aliasMiddleware('basalto.site', ResolveSiteFromHost::class);
        $this->app->make(Router::class)->aliasMiddleware(TrackPageView::ALIAS, TrackPageView::class);
        // Die Site muss feststehen, bevor Routen-Modelle gebunden werden — sonst findet die Bindung fremde Inhalte (403 statt 404).
        $kernel = $this->app->make(KernelContract::class);
        $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, SelectBackendSite::class);
        // D-12: Frontend-Hinweis nach 30 Tagen «unregistriert» — über den Kernel in die web-Gruppe (der Router-Stand wird vom Kernel überschrieben).
        $kernel->appendMiddlewareToGroup('web', ShowLicenseBanner::class);
        // TB 64: Testumgebung ohne Suchmaschinen (BASALTO_NOINDEX)
        $kernel->pushMiddleware(KeepOutOfSearchEngines::class);
        // B-06: Zustand der Singletons je Anfrage leeren (Octane) — und vor jedem Job im Worker, nicht bei «sync» (mitten in einer Anfrage)
        $kernel->prependMiddleware(ResetRequestState::class);
        Queue::before(static function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                ResetRequestState::reset();
            }
        });
        if ($this->app->runningInConsole()) {
            $this->commands([HeartbeatCommand::class, VerifyDomainsCommand::class, InstallCommand::class, LoginLinkCommand::class, LicenseCommand::class, BenchmarkCommand::class, PurgeMediaCommand::class, PublishScheduledCommand::class, CleanupCommand::class, PageCacheCommand::class, SeedCommand::class, PublishCommand::class, LegalCommand::class, PrivacyCommand::class, SearchIndexCommand::class, TokensCommand::class, ThemePackCommand::class, ThemeVerifyCommand::class]);
            // TB 09 Scheibe 4: Export/Import einer Site (M-18), Sicherung und Wiederherstellungsprüfung (K-17, B-10)
            $this->commands([ExportCommand::class, ImportCommand::class, BackupCommand::class, BackupVerifyCommand::class]);
            // TB 10 Scheibe 6 (B-09): Alarm bei Fehlerhäufung
            $this->commands([ErrorAlarmCommand::class]);
            // TB 12 Scheibe 5 (N-08): HTTP-Lasttest gegen eigene Adressen
            $this->commands([LoadTestCommand::class]);
            // TB 11 Scheibe 4 (G-10, G-08): Demo neu aufsetzen — nur mit BASALTO_DEMO=true; Doku aus docs/ als Seiten
            $this->commands([DemoResetCommand::class, DocsImportCommand::class]);
            // CR-004 (P-03): lokale Landschaft des Herstellers unter Herd; TB 64: Testumgebung auf cyon
            $this->commands([LocalCommand::class, TestingCommand::class]);
            // CR-004 (P-05): Release-Archiv für den Web-Installer
            $this->commands([ReleaseCommand::class]);
            // Testdaten für Entwicklung, Test-Umgebungen und Demo
            $this->commands([TestdataCommand::class]);
            // CR-007: Updates prüfen und einspielen
            $this->commands([UpdateCommand::class]);
            // CR-011 TB 59: vorgemerkte Kündigungen ausführen
            $this->commands([CancellationsCommand::class]);
            // Zeitzone der Datenbank-Session: alte TIMESTAMP-Werte auf UTC umrechnen
            $this->commands([DatabaseTimezoneCommand::class]);
        }
        // Zeitplan aus dem Paket: Herzschlag jede Minute, Domain-Prüfung alle zehn Minuten, Lizenzstand stündlich — je nur auf einem Server (Cloud-Replikate).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('basalto:heartbeat')->everyMinute()->onOneServer();
            $schedule->command('basalto:publish-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('basalto:domains-verify')->everyTenMinutes()->onOneServer();
            $schedule->command('basalto:license')->hourly()->onOneServer();
            // CR-007 (U-11): täglich prüfen, ob es Updates gibt — die Verwaltung erfährt es einmal je neuem Stand
            if ((bool) config('basalto.updates.check_daily', true) && ! Saas::enabled()) {
                $schedule->command('basalto:update --benachrichtigen')->dailyAt('04:10')->onOneServer();
            }
            $schedule->command('basalto:media-purge')->dailyAt('03:30')->onOneServer();
            $schedule->command('basalto:cleanup')->dailyAt('03:00')->onOneServer();
            // CR-011 TB 59: Kündigungen mit Wunschdatum rechtzeitig ausführen
            $schedule->command('basalto:kuendigungen')->dailyAt('02:50')->onOneServer();
            // TB 09 Scheibe 4 (K-17, B-10): Sicherung von Datenbank und Medien nach dem Löschlauf, Aufbewahrung «backup.keep»
            $schedule->command('basalto:backup')->dailyAt('03:15')->onOneServer();
            // TB 10 Scheibe 6 (B-09): Fehlerhäufung jede Minute prüfen, Mail gedrosselt
            $schedule->command('basalto:error-alarm')->everyMinute()->onOneServer();
            Health::scheduleQueue($schedule);
            // TB 11 Scheibe 4 (G-10): die öffentliche Demo jede Nacht neu — nur im Demo-Modus eingeplant
            if (DemoMode::enabled()) {
                $schedule->command('basalto:demo-reset', ['--force'])->dailyAt('02:30')->onOneServer();
            }
        });
        // TB 11 Scheibe 4 (G-10): im Demo-Modus jede Mail abfangen statt zustellen
        DemoMode::boot();
        // TB 64: Mails an reservierte Adressen (Testdaten @testdaten.example) gehen nie hinaus
        UndeliverableRecipients::boot();
        // CR-007: Der Freipass für den Wartungsmodus wird vor der Entschlüsselung gelesen — er darf nicht verschlüsselt sein
        EncryptCookies::except('laravel_maintenance');
        // TB 54: Löschlauf der Statistik — ältere Seitenaufrufe nach der eingestellten Frist
        $this->app->instance('basalto.cleanup.statistics', static function (): array {
            $count = DB::table('analytics_page_views')->where('created_at', '<', now()->subDays(app(Statistics::class)->retentionDays()))->delete();

            return $count > 0 ? ['Seitenaufrufe der Statistik' => $count] : [];
        });
        $this->app->tag(['basalto.cleanup.statistics'], 'basalto.cleanup');
        // CR-011 TB 59: Kündigungen als Beleg fünf Jahre aufbewahren (Verjährung periodischer Leistungen, Art. 128 OR)
        $this->app->instance('basalto.cleanup.cancellations', static function (): array {
            $count = DB::table('contract_cancellations')->where('received_at', '<', now()->subYears(5))->delete();

            return $count > 0 ? ['Kündigungen älter als fünf Jahre' => $count] : [];
        });
        $this->app->tag(['basalto.cleanup.cancellations'], 'basalto.cleanup');
        // CR-011 TB 61: erledigte Anliegen zum Datenschutz drei Jahre nach der Antwort löschen
        $this->app->instance('basalto.cleanup.privacy_requests', static function (): array {
            $count = DB::table('privacy_requests')->where('status', PrivacyRequest::DONE)->where('handled_at', '<', now()->subYears(3))->delete();

            return $count > 0 ? ['Erledigte Anliegen zum Datenschutz' => $count] : [];
        });
        $this->app->tag(['basalto.cleanup.privacy_requests'], 'basalto.cleanup');
        app(ContractSources::class)->register(app(SaasContractSource::class));
        // TB 54: Einwilligung und pseudonyme Kennungen der Statistik setzt der Browser — unverschlüsselt
        EncryptCookies::except([Statistics::CONSENT_COOKIE, Statistics::VISITOR_COOKIE, Statistics::SESSION_COOKIE]);
        app(Testdata::class)->register(CoreTestdata::KEY, 'Team, Kundenkonten und Community', 0, static fn (Site $site): bool => true,
            static fn (TestdataRun $run) => app(CoreTestdata::class)->seed($run), static fn (Site $site) => app(CoreTestdata::class)->remove($site));
        // TB 10 Scheibe 6 (B-09): Gate «viewPulse» für Betreiber der Installation, Pulse ohne Gravatar, Fehlerzähler
        MonitoringSetup::boot($this->app);
        // Letzter Lauf jeder Aufgabe für die Seite «Betrieb» (K-17)
        Event::listen(ScheduledTaskFinished::class, static fn (ScheduledTaskFinished $e) => Health::recordTask($e->task, $e->task->exitCode === 0 || $e->task->exitCode === null ? 'ok' : 'fehler', $e->runtime));
        Event::listen(ScheduledTaskFailed::class, static fn (ScheduledTaskFailed $e) => Health::recordTask($e->task, 'fehler', null));

        $backend = $this->app->make(Backend::class);
        $backend->pages('core', $this->root.'/resources/js/pages');
        $backend->module('core', 'basalto::backend.core', switchable: false, composerJson: $this->root.'/composer.json');
        $backend->menu('basalto::backend.dashboard', 'basalto.dashboard', 0, Backend::MODE_CONTENT, icon: 'overview');
        $backend->menu('basalto::backend.pages', 'basalto.pages.index', 3, Backend::MODE_CONTENT, icon: 'pages');
        $backend->menu('basalto::backend.entries', 'basalto.entries.index', 5, Backend::MODE_CONTENT, icon: 'blog');
        $backend->menu('basalto::backend.reviews', 'basalto.reviews.index', 6, Backend::MODE_CONTENT, icon: 'review');
        $backend->menu('basalto::backend.translations', 'basalto.translations.index', 7, Backend::MODE_CONTENT, icon: 'translation');
        $backend->menu('basalto::backend.forms', 'basalto.forms.index', 8, Backend::MODE_CONTENT, icon: 'forms');
        $backend->menu('basalto::backend.media', 'basalto.media.index', 10, Backend::MODE_CONTENT, icon: 'media');
        $backend->menu('basalto::backend.themes', 'basalto.setup.themes', 20, Backend::MODE_SETUP, icon: 'theme');
        $backend->menu('basalto::backend.design', 'basalto.setup.design', 25, Backend::MODE_SETUP, icon: 'theme');
        $backend->menu('basalto::backend.theme_editor', 'basalto.setup.theme-editor', 27, Backend::MODE_SETUP, icon: 'theme');
        $backend->menu('basalto::backend.layouts', 'basalto.setup.layouts.index', 30, Backend::MODE_SETUP, icon: 'theme');
        $backend->menu('basalto::backend.snippets', 'basalto.setup.snippets.index', 55, Backend::MODE_SETUP, icon: 'module'); // TB 10 Scheibe 3
        $backend->menu('basalto::backend.navigation', 'basalto.setup.navigation.index', 40, Backend::MODE_SETUP, icon: 'line');
        $backend->menu('basalto::backend.types', 'basalto.setup.types.index', 50, Backend::MODE_SETUP, icon: 'type');
        $backend->menu('basalto::backend.sites', 'basalto.sites.index', 20, Backend::MODE_ADMIN, icon: 'sites');
        $backend->menu('Module', 'basalto.modules', 22, Backend::MODE_ADMIN, icon: 'module'); // TB 11 Scheibe 2 (M-20): Module und Marktplatz
        $backend->menu('basalto::backend.users', 'basalto.users.index', 30, Backend::MODE_ADMIN, icon: 'users');
        $backend->menu('Rollen', 'basalto.roles.index', 31, Backend::MODE_ADMIN, icon: 'users'); // CR-005 (R-08)
        $backend->menu('basalto::backend.settings', 'basalto.settings.index', 40, Backend::MODE_ADMIN, icon: 'settings');
        $backend->menu('basalto::backend.license', 'basalto.license', 45, Backend::MODE_ADMIN, icon: 'settings');
        // CR-007: Updates — die Zahl kommt aus dem letzten Check (ohne Netz)
        $backend->menu('Updates', 'basalto.updates', 41, Backend::MODE_ADMIN, icon: 'updates');
        $backend->count('basalto.updates', static fn (): int => (int) (app(UpdateCheck::class)->cached()['count'] ?? 0));
        // TB 54: Statistik der Website (Seitenaufrufe, wie Fristo)
        $backend->menu('Statistik', 'basalto.statistics', 46, Backend::MODE_ADMIN, icon: 'statistics');
        // CR-011 TB 61: Anliegen zum Datenschutz — die Zahl nennt die offenen
        $backend->menu('Datenschutz', 'basalto.privacy.requests.index', 48, Backend::MODE_ADMIN, icon: 'user');
        $backend->count('basalto.privacy.requests.index', static fn (Site $site): int => PrivacyRequest::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('status', PrivacyRequest::OPEN)->count());
        $backend->menu('basalto::backend.operations', 'basalto.operations', 47, Backend::MODE_ADMIN, icon: 'activity');
        $backend->menu('basalto::backend.activity', 'basalto.activity.index', 50, Backend::MODE_ADMIN, icon: 'activity');
        $backend->menu('basalto::backend.passkeys', 'basalto.passkeys', 90, Backend::MODE_ADMIN, icon: 'user');

        // Inertia-Seiten mit Namensraum («core::Dashboard», «example::Hello») — Kern und Module liefern je ein Verzeichnis.
        $this->app->extend('inertia.view-finder', static function (FileViewFinder $finder) use ($backend): FileViewFinder {
            foreach ($backend->pageNamespaces() as $namespace => $path) {
                $finder->addNamespace($namespace, $path);
            }

            return $finder;
        });
    }
}
