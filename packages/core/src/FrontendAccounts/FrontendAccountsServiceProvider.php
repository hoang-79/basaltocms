<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Cache\PageCache;
use Basalto\Core\Content\Share;
use Basalto\Core\FrontendAccounts\Http\CustomerLoginController;
use Basalto\Core\FrontendAccounts\Http\CustomerPasskeyController;
use Basalto\Core\FrontendAccounts\Http\Middleware\AuthenticateCustomer;
use Basalto\Core\FrontendAccounts\Http\Middleware\PrivateForCustomers;
use Basalto\Core\FrontendAccounts\Http\PrivacyController;
use Basalto\Core\FrontendAccounts\Http\SocialLoginController;
use Basalto\Core\FrontendAccounts\Hub\AccountAreas;
use Basalto\Core\FrontendAccounts\Hub\AccountHub;
use Basalto\Core\FrontendAccounts\Hub\LoginsArea;
use Basalto\Core\FrontendAccounts\Hub\PrivacyArea;
use Basalto\Core\FrontendAccounts\Hub\SitesArea;
use Basalto\Core\Http\Controllers\FrontendController;
use Basalto\Core\Http\Controllers\SocialSettingsController;
use Basalto\Lava\Runtime\Context;
use Basalto\Lava\Runtime\Limits;
use Basalto\Lava\Runtime\Registry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Das Frontend-Konto im Kern (TB 13 Scheibe 2; vorher Teil des Lizenzverkaufs, TB 11 Scheibe 1): Guard `customer`
 * (eigene Sitzung, keine Backend-Benutzer), Anmeldung per Mail-Code unter `/account/login` → `/account/code`,
 * `/account/logout`, die Anmeldeseite `GET /account/login`; Middleware `basalto.customer`; angemeldete Konten bekommen und füllen nie den Seiten-Cache.
 * Die Routen antworten nur, wenn auf der Site ein Modul an ist, das das Konto nutzt (`CustomerAuth::useFor`) —
 * Lizenzverkauf, Marktplatz oder Mitglieder. Die Tabellen bleiben `customers` und `customer_login_codes`.
 *
 * TB 53: Anmeldung über Google, Microsoft, GitHub oder LinkedIn unter `/account/oauth/{anbieter}` (Rückkehr
 * `…/callback`) und mit Passkey unter `/account/passkey/…`; Bereich «Anmeldung» im Konto zum Verknüpfen, Trennen und
 * für Passkeys.
 *
 * TB 48: das Kundenkonto als Ganzes — Tag und Teil `{% account %}` (`AccountHub`) mit den Bereichen, die Kern und
 * Module im Register `AccountAreas` anmelden (hier: «Sites» mit der Verbindung zum Backend und zur Cloud).
 */
final class FrontendAccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Eigener Guard neben dem Backend (`web`): Konten des Frontends sind keine Benutzer
        config()->set('auth.guards.'.CustomerAuth::GUARD, ['driver' => 'session', 'provider' => CustomerAuth::PROVIDER]);
        config()->set('auth.providers.'.CustomerAuth::PROVIDER, ['driver' => 'eloquent', 'model' => Customer::class]);
        $this->app->singleton(AccountAreas::class);
        $this->app->extend(Registry::class, static function (Registry $registry): Registry {
            $render = static fn (array $named, Context $ctx): string => app(AccountHub::class)->render($named, $ctx);

            // TB 51: Teilen — Adresse und Titel der Seite, auf Wunsch eigene («title:», «url:»)
            $registry->tag('share', static function (array $args, array $named, Context $ctx): string {
                $page = is_array($ctx->get('page')) ? $ctx->get('page') : [];
                $site = is_array($ctx->get('site')) ? $ctx->get('site') : [];
                $url = is_string($named['url'] ?? null) && $named['url'] !== '' ? $named['url'] : (string) ($page['canonical'] ?? $page['url'] ?? '');
                $title = is_string($named['title'] ?? null) && $named['title'] !== '' ? $named['title'] : (string) ($page['title'] ?? '');
                $lang = is_string($page['locale'] ?? null) ? $page['locale'] : (string) ($site['language'] ?? 'de');

                return $url === '' ? '' : Share::html($url, $title, $lang);
            });

            return $registry->tag('account', static fn (array $args, array $named, Context $ctx): string => $render($named, $ctx))
                ->part('account', 'Kundenkonto', ['buy', 'entry', 'catalog', 'start'], static fn (array $settings, array $vars, string $lang): string => $render($settings, new Context($vars, app(Registry::class), new Limits)));
        });
    }

    public function boot(): void
    {
        PageCache::personalGuard(CustomerAuth::GUARD);
        $this->app->make(AccountAreas::class)->add(SitesArea::class);
        $this->app->make(AccountAreas::class)->add(LoginsArea::class);
        // CR-011 TB 61: die Rechte nach DSGVO und DSG zum Selbermachen
        $this->app->make(AccountAreas::class)->add(PrivacyArea::class);
        $this->app->make(Router::class)->aliasMiddleware(CustomerAuth::MIDDLEWARE, AuthenticateCustomer::class);
        // Über den Kernel in die web-Gruppe — den Stand des Routers überschreibt der Kernel (wie ShowLicenseBanner)
        $this->app->make(KernelContract::class)->appendMiddlewareToGroup('web', PrivateForCustomers::class);
        RateLimiter::for('basalto-account-login', static fn (Request $request): Limit => Limit::perMinute(5)->by('account|'.$request->ip().'|'.mb_strtolower((string) $request->input('email', ''))));
        RateLimiter::for('basalto-account-verify', static fn (Request $request): Limit => Limit::perMinute(20)->by('account-verify|'.$request->ip()));

        // Code anfordern ohne CSRF-Token (die Seite kommt aus dem Seiten-Cache; ausgenommen wird `PreventRequestForgery`,
        // die Middleware der web-Gruppe in Laravel 13), Code prüfen und abmelden mit Token
        Route::middleware(['web', 'basalto.site'])->group(static function (): void {
            Route::post('account/login', [CustomerLoginController::class, 'request'])->withoutMiddleware(PreventRequestForgery::class)
                ->middleware('throttle:basalto-account-login')->name('basalto.account.login');
            Route::post('account/code', [CustomerLoginController::class, 'verify'])->middleware('throttle:basalto-account-verify')->name('basalto.account.code');
            Route::post('account/logout', [CustomerLoginController::class, 'logout'])->name('basalto.account.logout');
            // TB 13 Scheibe 2: eigene Anmeldeseite im Layout des Themes — Ziel von «Anmelden» und der Mails der Mitglieder
            Route::get('account/login', [FrontendController::class, 'login'])->name('basalto.account.login.page');
            // TB 53: Anmeldung über Google, Microsoft, GitHub oder LinkedIn — Weiterleiten, Rückkehr, Trennen
            Route::get('account/oauth/{provider}', [SocialLoginController::class, 'redirect'])->where('provider', 'google|microsoft|github|linkedin')
                ->middleware('throttle:basalto-account-verify')->name('basalto.account.social.redirect');
            Route::get('account/oauth/{provider}/callback', [SocialLoginController::class, 'callback'])->where('provider', 'google|microsoft|github|linkedin')
                ->middleware('throttle:basalto-account-verify')->name('basalto.account.social.callback');
            Route::post('account/oauth/trennen/{identity}', [SocialLoginController::class, 'unlink'])->whereNumber('identity')->name('basalto.account.social.unlink');
            // TB 53: Passkeys — anmelden (Optionen, Antwort), im Konto hinzufügen und entfernen
            Route::get('account/passkey/optionen', [CustomerPasskeyController::class, 'loginOptions'])->middleware('throttle:basalto-account-verify')->name('basalto.account.passkey.options');
            Route::post('account/passkey/anmelden', [CustomerPasskeyController::class, 'login'])->middleware('throttle:basalto-account-verify')->name('basalto.account.passkey.login');
            Route::get('account/passkey/neu/optionen', [CustomerPasskeyController::class, 'registrationOptions'])->middleware('throttle:basalto-account-verify')->name('basalto.account.passkey.registration-options');
            Route::post('account/passkey/neu', [CustomerPasskeyController::class, 'store'])->middleware('throttle:basalto-account-verify')->name('basalto.account.passkey.store');
            // Nicht «{passkey}»: das Paket bindet diesen Namen an die Passkeys der Backend-Benutzer
            Route::post('account/passkey/{key}/entfernen', [CustomerPasskeyController::class, 'destroy'])->whereNumber('key')->name('basalto.account.passkey.destroy');
        });
        // CR-011 TB 61: Daten herunterladen, berichtigen, Anliegen stellen, Konto löschen — angemeldet, nur auf dieser Site
        Route::middleware(['web', 'basalto.site', CustomerAuth::MIDDLEWARE])->group(static function (): void {
            Route::post('account/datenschutz/export', [PrivacyController::class, 'export'])->middleware('throttle:10,1')->name('basalto.account.privacy.export');
            Route::post('account/datenschutz/angaben', [PrivacyController::class, 'update'])->name('basalto.account.privacy.update');
            Route::post('account/datenschutz/anliegen', [PrivacyController::class, 'request'])->middleware('throttle:5,1')->name('basalto.account.privacy.request');
            Route::post('account/datenschutz/loeschen', [PrivacyController::class, 'destroy'])->middleware('throttle:5,1')->name('basalto.account.privacy.destroy');
        });

        // TB 53: Einrichtung der Anbieter je Site (Verwaltung › Sites › Anmeldung für Kundenkonten)
        $this->app->make(Backend::class)->routes(static function (): void {
            $providers = 'google|microsoft|github|linkedin';
            Route::get('sites/{site}/kundenanmeldung', [SocialSettingsController::class, 'edit'])->name('sites.social');
            Route::put('sites/{site}/kundenanmeldung/{provider}', [SocialSettingsController::class, 'update'])->where('provider', $providers)->name('sites.social.update');
            Route::delete('sites/{site}/kundenanmeldung/{provider}', [SocialSettingsController::class, 'destroy'])->where('provider', $providers)->name('sites.social.destroy');
            Route::post('sites/{site}/kundenanmeldung/{provider}/ausprobieren', [SocialSettingsController::class, 'probe'])->where('provider', $providers)
                ->middleware('throttle:10,1')->name('sites.social.probe');
        });

        // Löschlauf (N-05): Anmeldecodes nach einem Tag. Auskunft und Löschung ruft `PersonData` selbst — nach den Modulen
        $this->app->instance('basalto.cleanup.frontend-accounts', AccountPersonData::cleanup(...));
        $this->app->tag(['basalto.cleanup.frontend-accounts'], 'basalto.cleanup');
    }
}
