<?php

declare(strict_types=1);

namespace Basalto\Core\Saas;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Console\SaasBillingCommand;
use Basalto\Core\Console\SaasTrialCommand;
use Basalto\Core\Http\Controllers\Saas\AccountBillingController;
use Basalto\Core\Http\Controllers\Saas\AccountDomainsController;
use Basalto\Core\Http\Controllers\Saas\AccountExitController;
use Basalto\Core\Http\Controllers\Saas\AccountPlanController;
use Basalto\Core\Http\Controllers\Saas\AccountSitesController;
use Basalto\Core\Http\Controllers\Saas\AccountUsersController;
use Basalto\Core\Http\Controllers\Saas\RegistrationController;
use Basalto\Core\Http\Controllers\Saas\SaasWebhookController;
use Basalto\Core\Http\Middleware\EnsureSaasMode;
use Basalto\Core\Http\Middleware\HandleBackendRequests;
use Basalto\Core\Http\Middleware\ResolveSaasAccount;
use Basalto\Core\Http\Middleware\SelectGuestLocale;
use Basalto\Core\Models\SaasRegistration;
use Basalto\Core\Models\Site;
use Basalto\Core\Operations\Operators;
use Basalto\Core\Saas\Billing\BookExtraDomain;
use Basalto\Core\Saas\Billing\CashierModels;
use Basalto\Core\Saas\Billing\StripeSubscriptionGateway;
use Basalto\Core\Saas\Billing\SubscriptionGateway;
use Basalto\Core\Saas\Events\ExtraDomainRequested;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

/**
 * Die SaaS im Kern (TB 12, M-21, ADR-008) — an mit `BASALTO_SAAS=true`, sonst sind alle Routen 404 und die Menüs leer.
 * Im Kern, nicht als Modul: Konto, Sites und Benutzer sind Kern, und die Download-Installation braucht dieselben Modelle.
 *
 * Scheibe 2 bringt Abo und Rechnungen: Konto als Billable ({@see CashierModels}), Reiter «Abo und Rechnungen»
 * (`basalto.saas.account.billing*`), Webhook `/stripe/saas-webhook`, `basalto:saas-billing` (stündlich).
 *
 * Scheibe 1 bringt: Registrierung unter `<backend>/registrieren` (`basalto.saas.register*`), den Konto-Bereich unter
 * `<backend>/konto/…` (`basalto.saas.account.*`, ein Controller und eine Vue-Seite je Reiter, {@see AccountPage}), die
 * Testphase mit Erinnerungen (`basalto:saas-trial`, stündlich) und Hinweisband/Fussleiste. Die weiteren Scheiben hängen
 * ihre Routen hier an (Abschnitt je Scheibe) und füllen die Reiter Abo (2), Domains (3), Export und Kündigung (4).
 */
final class SaasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Scheibe 2: Cashier je Rolle (SaaS: Konto zahlt), eigene Webhook-Route statt der von Cashier, Stripe als Abo-Weg ──
        CashierModels::apply();
        Cashier::ignoreRoutes();
        $this->app->bind(SubscriptionGateway::class, StripeSubscriptionGateway::class);
    }

    public function boot(): void
    {
        $backend = $this->app->make(Backend::class);
        $this->routes($backend);

        // Verwaltung › Konto nur für Konto-Rollen eines Kundenkontos; «Lizenz» gibt es auf der SaaS nicht (Lizenz im Abo)
        $backend->menu('Konto', 'basalto.saas.account.index', 15, Backend::MODE_ADMIN, icon: 'user');
        $backend->visibleWhen('basalto.saas.account.index', static fn (?Authenticatable $user, ?Site $site): bool => Saas::enabled() && $user !== null && AccountContext::resolve($user, $site) !== null);
        $backend->visibleWhen('basalto.license', static fn (): bool => ! Saas::enabled());
        // Betrieb und Module gehören auf der SaaS der Instanz (GuardSaasInstanceAreas) — im Menü nur für den Betreiber
        foreach (['basalto.operations', 'basalto.modules'] as $route) {
            $backend->visibleWhen($route, static fn (?Authenticatable $user): bool => ! Saas::enabled() || Operators::allows($user));
        }

        // Scheibe 2: bestellte Zusatzdomains (Scheibe 3) ins Abo übernehmen
        Event::listen(ExtraDomainRequested::class, BookExtraDomain::class);

        // Registrierungen je IP: zehn Anfragen je Stunde (Absenden, Code neu senden) — dazu der Spam-Schutz der Formulare
        RateLimiter::for('basalto-saas-register', static fn (Request $request): Limit => Limit::perHour(10)->by('saas-register|'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([SaasTrialCommand::class, SaasBillingCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (Saas::enabled()) {
                $schedule->command('basalto:saas-trial')->hourly()->onOneServer();
                // Scheibe 2: Herabstufungen, QR-Rechnungen je Periode, Erinnerungen — idempotent
                $schedule->command('basalto:saas-billing')->hourly()->onOneServer();
            }
        });
        // Offene Registrierungen nach sieben Tagen, abgeschlossene nach 30 Tagen löschen (N-05); die Zustimmung steht am Konto
        $this->app->instance('basalto.cleanup.saas', static function (): array {
            $count = SaasRegistration::query()->whereNull('completed_at')->where('created_at', '<', now()->subDays(7))->delete()
                + SaasRegistration::query()->whereNotNull('completed_at')->where('completed_at', '<', now()->subDays(30))->delete();

            return $count > 0 ? ['Registrierungen' => $count] : [];
        });
        $this->app->tag(['basalto.cleanup.saas'], 'basalto.cleanup');
    }

    private function routes(Backend $backend): void
    {
        // ── Scheibe 1: Registrierung (Gast-Seiten mit der Blade-Wurzel des Backends, Sprache aus dem Browser) ──
        Route::middleware(['web', SelectGuestLocale::class, HandleBackendRequests::class, EnsureSaasMode::class.':registration'])
            ->prefix($backend->path().'/registrieren')->name('basalto.saas.')->group(static function (): void {
                Route::get('/', [RegistrationController::class, 'show'])->name('register');
                Route::post('/', [RegistrationController::class, 'start'])->middleware('throttle:basalto-saas-register')->name('register.start');
                Route::post('code', [RegistrationController::class, 'verify'])->middleware('throttle:basalto-login-verify')->name('register.verify');
                Route::get('link/{token}', [RegistrationController::class, 'link'])->where('token', '[A-Za-z0-9]{64}')->name('register.link');
                Route::post('neu-senden', [RegistrationController::class, 'resend'])->middleware('throttle:basalto-saas-register')->name('register.resend');
                Route::post('zuruecksetzen', [RegistrationController::class, 'reset'])->name('register.reset');
                Route::post('site', [RegistrationController::class, 'complete'])->name('register.complete');
                Route::get('themes/{name}/bild', [RegistrationController::class, 'theme'])->where('name', '[a-z0-9][a-z0-9_-]*')->name('register.theme');
            });
        // Alias-Prüfung beim Tippen — für die Registrierung und für «Weitere Site» im Konto (auch auf der Instanz «pro»)
        Route::middleware(['web', EnsureSaasMode::class, 'throttle:120,1'])->get($backend->path().'/registrieren/alias', [RegistrationController::class, 'alias'])->name('basalto.saas.register.alias');

        // ── Scheibe 1: Konto-Bereich (Backend, Konto aus der Anfrage, fremde Konten und Sites 404) ──
        $backend->routes(static function (): void {
            Route::middleware([EnsureSaasMode::class, ResolveSaasAccount::class])->prefix('konto')->name('saas.account.')->group(static function (): void {
                Route::get('/', [AccountSitesController::class, 'start'])->name('index');
                Route::get('sites', [AccountSitesController::class, 'index'])->name('sites');
                Route::post('sites', [AccountSitesController::class, 'store'])->name('sites.store');
                Route::post('sites/{site}/oeffnen', [AccountSitesController::class, 'open'])->whereNumber('site')->name('sites.open');
                Route::get('plan', AccountPlanController::class)->name('plan');
                Route::get('benutzer', [AccountUsersController::class, 'index'])->name('users');
                Route::post('benutzer', [AccountUsersController::class, 'store'])->name('users.store');
                Route::put('benutzer/{user}', [AccountUsersController::class, 'update'])->whereNumber('user')->name('users.update');
                Route::delete('benutzer/{user}', [AccountUsersController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
                // Erweiterungspunkte: Scheibe 2 (Abo und Rechnungen), 3 (Domains), 4 (Export und Kündigung)
                Route::get('abo', AccountBillingController::class)->name('billing');
                // ── Scheibe 2: Abo und Rechnungen (Besitzer und Abrechnung) ──
                Route::post('abo/waehlen', [AccountBillingController::class, 'choose'])->name('billing.choose');
                Route::post('abo/wechseln', [AccountBillingController::class, 'change'])->name('billing.change');
                Route::post('abo/zusaetze', [AccountBillingController::class, 'extras'])->name('billing.extras');
                Route::post('abo/adresse', [AccountBillingController::class, 'address'])->name('billing.address');
                Route::post('abo/zahlungsart', [AccountBillingController::class, 'method'])->name('billing.method');
                Route::post('abo/karte', [AccountBillingController::class, 'portal'])->name('billing.portal');
                Route::get('abo/rechnungen/{invoice}', [AccountBillingController::class, 'invoice'])->whereNumber('invoice')->name('billing.invoice');
                Route::get('domains', AccountDomainsController::class)->name('domains');
                // TB 12 Scheibe 3: Alias ändern, eigene Domain hinzufügen, prüfen, Hauptadresse, entfernen
                Route::post('domains/{site}/alias', [AccountDomainsController::class, 'alias'])->whereNumber('site')->name('domains.alias');
                Route::post('domains/{site}', [AccountDomainsController::class, 'store'])->whereNumber('site')->name('domains.store');
                Route::post('domains/{site}/{domain}/pruefen', [AccountDomainsController::class, 'check'])->whereNumber(['site', 'domain'])->name('domains.check');
                Route::post('domains/{site}/{domain}/hauptadresse', [AccountDomainsController::class, 'primary'])->whereNumber(['site', 'domain'])->name('domains.primary');
                Route::delete('domains/{site}/{domain}', [AccountDomainsController::class, 'destroy'])->whereNumber(['site', 'domain'])->name('domains.destroy');
                Route::get('kuendigung', AccountExitController::class)->name('exit');
            });
        });

        // ── Scheibe 2: Stripe-Webhook der SaaS (ohne Sitzung und CSRF, Signatur Pflicht) — eigener Pfad neben dem des Lizenzverkaufs ──
        Route::post((string) config('basalto.saas.billing.webhook_path', 'stripe/saas-webhook'), [SaasWebhookController::class, 'handleWebhook'])->name('basalto.saas.webhook');
    }
}
