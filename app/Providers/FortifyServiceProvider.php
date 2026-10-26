<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Fortify liefert nur noch die Passkeys (K-04): Registrierung, Passwort-Reset, Zwei-Faktor und die Starter-Kit-Ansichten
 * sind entfernt; der Passwort-Login bleibt als Endpunkt bestehen, lehnt aber jede Anmeldung ab, solange
 * «basalto.auth.password_login» aus ist (Installationen ohne Mail dürfen ihn einschalten).
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::authenticateUsing(static function (Request $request): mixed {
            if (! config('basalto.auth.password_login', false)) {
                return null;
            }
            $model = config('auth.providers.users.model');
            $user = $model::query()->where('email', $request->input('email'))->first();

            return $user !== null && is_string($user->password) && password_verify((string) $request->input('password'), $user->password) ? $user : null;
        });

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('passkeys', static fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
