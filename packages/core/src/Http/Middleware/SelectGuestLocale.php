<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Support\BackendLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gast-Seiten des Backends (Anmeldung, Einladung, Installer): solange niemand angemeldet ist, spricht die Oberfläche
 * die Sprache des Browsers (`Accept-Language`) — ohne Angabe Deutsch. Angemeldete bekommen die Sprache ihres Profils.
 */
final class SelectGuestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = BackendLocale::of($request->user()) ?? $request->getPreferredLanguage(BackendLocale::LOCALES);
        if (is_string($locale) && in_array($locale, BackendLocale::LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
