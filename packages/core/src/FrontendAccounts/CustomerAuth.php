<?php

declare(strict_types=1);

namespace Basalto\Core\FrontendAccounts;

use Basalto\Core\Backend\Backend;
use Basalto\Core\Models\Site;
use Basalto\Core\Sites\CurrentSite;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Die öffentliche Schnittstelle des Frontend-Kontos für Module (Lizenzverkauf, Marktplatz, Mitglieder): wer im
 * Frontend angemeldet ist, an- und abmelden. Der Guard `customer` ist eine eigene Sitzung neben dem Backend (`web`);
 * sein Provider liest nur Konten der aktuellen Site. Seiten mit angemeldetem Konto gehen nie in den Seiten-Cache
 * (`PageCache::personalGuard`). Seit TB 13 Scheibe 2 im Kern — vorher `Basalto\Modules\Licensing\Accounts\CustomerAuth`.
 */
final class CustomerAuth
{
    public const GUARD = 'customer';

    public const PROVIDER = 'customers';

    /** Die Middleware, die ein Frontend-Konto verlangt (Alias). */
    public const MIDDLEWARE = 'basalto.customer';

    /** Wo die Module stehen, die das Frontend-Konto nutzen (Konfiguration statt statischer Eigenschaft — Octane). */
    public const MODULES = 'basalto.accounts.modules';

    /** Regeln der Module für neue Konten: Modul → Klasse mit `RegistrationPolicy`. */
    public const POLICIES = 'basalto.accounts.policies';

    /**
     * Ein Modul nutzt das Frontend-Konto (Lizenzverkauf, Marktplatz, Mitglieder) — beim Booten aufrufen. Mit Regel
     * entscheidet das Modul je Site, ob die Anmeldung neue Konten anlegt; ohne Regel legt sie sie an.
     *
     * @param  class-string<RegistrationPolicy>|null  $policy
     */
    public static function useFor(string $module, ?string $policy = null): void
    {
        $modules = (array) config(self::MODULES, []);
        if (! in_array($module, $modules, true)) {
            config()->set(self::MODULES, [...$modules, $module]);
        }
        if ($policy !== null) {
            config()->set(self::POLICIES.'.'.$module, $policy);
        }
    }

    /** Gibt es auf dieser Site ein Frontend-Konto — ist eines der nutzenden Module an? */
    public static function available(Site $site): bool
    {
        return self::enabledModules($site) !== [];
    }

    /** Legt die Anmeldung auf dieser Site neue Konten an? Ja, sobald ein eingeschaltetes Modul es zulässt. */
    public static function opensRegistration(Site $site): bool
    {
        foreach (self::enabledModules($site) as $module) {
            $policy = config(self::POLICIES.'.'.$module);
            if (! is_string($policy) || ! is_a($policy, RegistrationPolicy::class, true) || app($policy)->opensRegistration($site)) {
                return true;
            }
        }

        return false;
    }

    public static function guard(): StatefulGuard
    {
        return Auth::guard(self::GUARD);
    }

    /** Das angemeldete Konto — nur, wenn es zur Site der Anfrage gehört. */
    public static function current(): ?Customer
    {
        if (config('auth.guards.'.self::GUARD) === null) {
            return null;
        }
        $customer = self::guard()->user();
        if (! $customer instanceof Customer) {
            return null;
        }
        $site = app(CurrentSite::class)->id();

        return $site === null || $customer->site_id === $site ? $customer : null;
    }

    public static function login(Request $request, Customer $customer): void
    {
        self::guard()->login($customer);
        $request->session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->save();
    }

    /** Abmelden nur aus dem Frontend-Konto — eine Backend-Sitzung im selben Browser bleibt. */
    public static function logout(Request $request): void
    {
        self::guard()->logout();
        $request->session()->forget([CustomerLogin::SESSION_EMAIL, CustomerLogin::SESSION_PURPOSE]);
        $request->session()->regenerateToken();
    }

    /** @return list<string> eingeschaltete Module dieser Site, die das Konto nutzen */
    private static function enabledModules(Site $site): array
    {
        $backend = app(Backend::class);
        $out = [];
        foreach ((array) config(self::MODULES, []) as $module) {
            if (is_string($module) && $backend->moduleEnabled($module, $site)) {
                $out[] = $module;
            }
        }

        return $out;
    }
}
