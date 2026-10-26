<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Controllers;

use Basalto\Core\Activity\Recorder;
use Basalto\Core\Events\UserLoggedIn;
use Basalto\Core\Http\Middleware\SelectBackendSite;
use Basalto\Core\Models\Site;
use Basalto\Core\Models\SsoConnection;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Sso\SsoLogin;
use Basalto\Core\Sso\SsoProvider;
use Basalto\Core\Sso\SsoProviders;
use Basalto\Core\Sso\SsoRefusal;
use Basalto\Core\Sso\SsoSignIn;
use Basalto\Core\Sso\SsoVerifier;
use Basalto\Core\Support\Preferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * TB 13 Scheibe 4 (M-25): Anmeldung über Microsoft Entra ID oder Google für eine Site. Weiterleiten mit `state` und PKCE
 * (Socialite hält beides in der Sitzung), Rückkehr prüfen, nur bestehende Benutzer mit Rolle auf der Site anmelden.
 * Fehler gehen freundlich und ohne Einzelheiten an die Anmeldeseite; der Grund steht im Protokoll (`sso.refused`).
 */
final class SsoLoginController
{
    /** Sitzungsschlüssel: Site und Anbieter zwischen Weiterleiten und Rückkehr. */
    public const SESSION_KEY = 'basalto.sso';

    public function __construct(private readonly SsoProviders $providers, private readonly Recorder $recorder) {}

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        $sso = SsoProvider::from($provider);
        $site = SsoLogin::site($request);
        $connection = $site === null ? null : SsoConnection::active($site, $sso);
        if ($site === null || $connection === null) {
            return $this->fail(__('Die Anmeldung über :provider ist für diese Site nicht eingerichtet.', ['provider' => $sso->shortName()]));
        }
        $request->session()->put(self::SESSION_KEY, ['site_id' => $site->id, 'provider' => $sso->value]);
        $driver = $this->providers->for($connection);
        $parameters = ['prompt' => 'select_account'];
        if ($sso === SsoProvider::Google && $connection->hosted_domain !== null && $connection->hosted_domain !== '') {
            // Nur Vorauswahl bei Google — geprüft wird `hd` bei der Rückkehr
            $parameters['hd'] = $connection->hosted_domain;
        }
        // Mit der Socialite-Attrappe in Tests ist der Treiber kein AbstractProvider — dann ohne Scopes und Parameter
        if ($driver instanceof AbstractProvider) {
            $driver->scopes($sso === SsoProvider::Entra ? ['openid', 'profile', 'email', 'User.Read'] : ['openid', 'email', 'profile'])->with($parameters)->enablePKCE();
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $sso = SsoProvider::from($provider);
        $pending = $request->session()->pull(self::SESSION_KEY);
        $siteId = is_array($pending) && ($pending['provider'] ?? null) === $sso->value && is_int($pending['site_id'] ?? null) ? $pending['site_id'] : null;
        $site = $siteId === null ? null : Site::query()->find($siteId);
        $connection = $site === null ? null : SsoConnection::active($site, $sso);
        if ($site === null || $connection === null) {
            return $this->fail(__('Die Anmeldung ist abgelaufen. Bitte versuchen Sie es noch einmal.'));
        }
        if ($request->query('error') !== null) {
            // Abgebrochen oder abgelehnt beim Anbieter (z. B. access_denied, fehlende Zustimmung der Verwaltung)
            $this->refused($site, $sso, 'provider_error', null);

            return $this->fail(__('Die Anmeldung bei :provider wurde abgebrochen.', ['provider' => $sso->shortName()]));
        }
        try {
            $account = $this->providers->for($connection)->user();
        } catch (InvalidStateException) {
            return $this->fail(__('Die Anmeldung ist abgelaufen. Bitte versuchen Sie es noch einmal.'));
        } catch (Throwable $e) {
            // Nur Klasse und Anbieter ins Log — die Antwort des Anbieters kann Kennungen der App tragen
            Log::warning('SSO: Rückkehr von '.$sso->value.' fehlgeschlagen', ['site_id' => $site->id, 'exception' => $e::class]);

            return $this->fail(__('Die Anmeldung bei :provider hat nicht geklappt. Versuchen Sie es noch einmal oder melden Sie sich per Mail-Code an.', ['provider' => $sso->shortName()]));
        }
        try {
            $identity = app(SsoVerifier::class)->verify($connection, $account);
            $user = app(SsoSignIn::class)->resolve($connection, $site, $identity);
        } catch (SsoRefusal $refusal) {
            $this->refused($site, $sso, $refusal->reason, $refusal->email);

            return $this->fail($refusal->message());
        }
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->put(SelectBackendSite::SESSION_KEY, $site->id);
        app(CurrentSite::class)->within($site, static fn () => event(new UserLoggedIn($user, 'sso-'.$sso->value)));

        return redirect()->intended(Preferences::startUrl($user));
    }

    /** Protokoll ohne Personendaten von Fremden: nur die Domain der E-Mail. */
    private function refused(Site $site, SsoProvider $sso, string $reason, ?string $email): void
    {
        $domain = $email === null || ! str_contains($email, '@') ? null : strtolower(substr($email, (int) strrpos($email, '@') + 1));
        $this->recorder->record('sso.refused', null, null, array_filter(['provider' => $sso->value, 'reason' => $reason, 'domain' => $domain], static fn (?string $v): bool => $v !== null), $site);
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('basalto.login')->withErrors(['sso' => $message]);
    }
}
