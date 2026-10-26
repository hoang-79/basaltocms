<?php

declare(strict_types=1);

namespace Basalto\Core\Sso;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Http\Controllers\SsoLoginController;
use Basalto\Core\Http\Controllers\SsoSettingsController;
use Basalto\Core\Http\Middleware\HandleBackendRequests;
use Basalto\Core\Http\Middleware\SelectGuestLocale;
use Basalto\Core\Models\SsoIdentity;
use Basalto\Core\Privacy\PersonScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * TB 13 Scheibe 4 (M-25): SSO für Backend-Benutzer im Kern — Microsoft Entra ID und Google über Socialite, eingerichtet
 * je Site (nicht je Konto: die Rollen hängen an der Site, eine Agentur hat in einem Konto Sites verschiedener Firmen mit
 * verschiedenen Tenants, und Download-Installationen haben ohnehin nur ein Konto). Im Kern statt als Modul, weil die
 * Anmeldeseite, die Benutzer und die Rollen Kern sind — wie Passkeys und Mail-Code.
 *
 * Routen: `<backend>/login/sso/{entra|google}` (Weiterleiten) und `…/callback` (Rückkehr) für Gäste, begrenzt wie die
 * Code-Prüfung; Einrichtung unter `<backend>/sites/{site}/anmeldung`.
 */
final class SsoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $backend = $this->app->make(Backend::class);
        // Datenschutz (N-05): Auskunft nennt die Verknüpfungen, Löschung entfernt sie (am Benutzer hängen sie ohnehin mit Kaskade)
        $this->app->instance('basalto.person-data.sso', static function (string $email, bool $delete): array {
            // CR-011 TB 61: Backend-Konten gehören nicht zur Auskunft aus dem Kundenkonto einer Site
            if (app(PersonScope::class)->siteId() !== null) {
                return [];
            }
            /** @var class-string<Model> $userModel */
            $userModel = config('auth.providers.users.model');
            $userId = $userModel::query()->where('email', $email)->value('id');
            $query = SsoIdentity::query()->where(static fn ($q) => $q->where('email', $email)->when($userId !== null, static fn ($q) => $q->orWhere('user_id', $userId)));
            if ($delete) {
                $count = $query->delete();

                return $count > 0 ? ['SSO-Verknüpfungen' => $count] : [];
            }
            $links = $query->orderBy('id')->get()->map(static fn (SsoIdentity $link): array => ['anbieter' => $link->provider, 'aussteller' => $link->issuer,
                'kennung' => $link->subject, 'verknuepft' => $link->created_at?->toIso8601String(), 'zuletzt' => $link->last_login_at?->toIso8601String()])->all();

            return $links === [] ? [] : ['sso_verknuepfungen' => $links];
        });
        $this->app->tag(['basalto.person-data.sso'], 'basalto.person-data');
        Route::middleware(['web', SelectGuestLocale::class, HandleBackendRequests::class, 'throttle:basalto-login-verify'])
            ->prefix($backend->path().'/login/sso')->group(static function (): void {
                Route::get('{provider}', [SsoLoginController::class, 'redirect'])->where('provider', 'entra|google')->name('basalto.login.sso');
                Route::get('{provider}/callback', [SsoLoginController::class, 'callback'])->where('provider', 'entra|google')->name('basalto.login.sso.callback');
            });
        $backend->routes(static function (): void {
            Route::get('sites/{site}/anmeldung', [SsoSettingsController::class, 'edit'])->name('sites.sso');
            Route::put('sites/{site}/anmeldung/{provider}', [SsoSettingsController::class, 'update'])->where('provider', 'entra|google')->name('sites.sso.update');
            Route::delete('sites/{site}/anmeldung/{provider}', [SsoSettingsController::class, 'destroy'])->where('provider', 'entra|google')->name('sites.sso.destroy');
            Route::post('sites/{site}/anmeldung/{provider}/testen', [SsoSettingsController::class, 'check'])->where('provider', 'entra|google')->middleware('throttle:10,1')->name('sites.sso.check');
        });
    }
}
