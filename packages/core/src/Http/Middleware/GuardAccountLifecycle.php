<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Accounts\AccountRole;
use Basalto\Core\Backend\Backend;
use Basalto\Core\Saas\Move\InstanceLink;
use Basalto\Core\Saas\Offboarding\Lifecycle;
use Basalto\Core\Saas\Saas;
use Basalto\Core\Sites\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * TB 12 Scheibe 4 (M-21): das Backend einer Site, deren Konto gesperrt ist, umzieht oder umgezogen ist.
 *
 * - **Gesperrt** (Laufzeit nach der Kündigung vorbei): nur der Besitzer des Kontos kommt hinein, und nur lesend — dazu
 *   Export, Abmelden und Site-Wechsel. Alle anderen bekommen 403 mit einem Satz.
 * - **Umziehend:** lesen ja, speichern nein — sonst ginge die Änderung auf der Quelle verloren.
 * - **Umgezogen:** Das Backend gehört der Zielinstanz — Weiterleitung dorthin (Abmelden geht noch).
 */
final class GuardAccountLifecycle
{
    /** Schreibende Routen, die auch gesperrt gehen. */
    public const LOCKED_WRITES = ['basalto.logout', 'basalto.site.switch', 'basalto.saas.account.exit.export'];

    public function __construct(private readonly CurrentSite $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Saas::enabled()) {
            return $next($request);
        }
        $account = $this->current->get()?->account;
        if (! Saas::isCustomer($account) || $account === null) {
            return $next($request);
        }
        $state = Lifecycle::state($account);
        $route = (string) $request->route()?->getName();
        $reading = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($state === Lifecycle::MOVED && $route !== 'basalto.logout') {
            try {
                return Inertia::location(InstanceLink::baseUrl((string) $account->instance).'/'.app(Backend::class)->path());
            } catch (Throwable) {
                abort(409, __('Dieses Konto ist auf eine andere Instanz umgezogen.'));
            }
        }
        if ($state === Lifecycle::LOCKED) {
            $user = $request->user();
            abort_unless($user !== null && $account->roleOf($user) === AccountRole::Owner, 403, __('Diese Site ist gesperrt — nur der Besitzer des Kontos kann sie noch lesen und exportieren.'));
            if (! $reading && ! in_array($route, self::LOCKED_WRITES, true)) {
                return back()->withErrors(['locked' => __('Gesperrt — das Backend ist nur lesbar. Export und Kündigung finden Sie unter Konto.')]);
            }
        }
        if ($state === Lifecycle::MOVING && ! $reading && ! in_array($route, ['basalto.logout', 'basalto.site.switch'], true)) {
            return back()->withErrors(['locked' => __('Der Umzug läuft — Änderungen lassen sich erst danach speichern.')]);
        }

        return $next($request);
    }
}
