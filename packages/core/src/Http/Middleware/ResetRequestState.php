<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Access\Access;
use Basalto\Core\Content\Pages;
use Basalto\Core\Extranet\ExtranetAccess;
use Basalto\Core\Sites\CurrentSite;
use Basalto\Core\Theme\ThemeLoader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Octane-tauglich (B-06): Unter Octane leben Singletons über viele Anfragen, und Laravel merkt sich Controller an der
 * Route — wer einen Dienst hält, hält ihn für immer. Statt die Dienste neu zu binden (dann hielten Controller veraltete
 * Objekte), leeren wir zu Beginn jeder Anfrage ihren Zustand: aktuelle Site, Pfadtabellen, kompilierte Themes, Sprache.
 * Dasselbe vor jedem Job im Worker ({@see self::reset()}).
 */
final class ResetRequestState
{
    public function handle(Request $request, Closure $next): Response
    {
        self::reset();

        return $next($request);
    }

    public static function reset(): void
    {
        $app = app();
        $app->make(CurrentSite::class)->forget();
        $app->make(Pages::class)->forget();
        $app->make(ThemeLoader::class)->reset();
        // CR-005: Rechte der Person je Site — nie aus einer früheren Anfrage
        $app->make(Access::class)->forget();
        $app->make(ExtranetAccess::class)->forget();
        // Nicht «app.locale»: das setzt jede Anfrage mit eigener Sprache (Profil, Seite, Browser) mit um
        $app->setLocale((string) config('basalto.default_locale', config('app.locale', 'de')));
    }
}
