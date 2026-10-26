<?php

declare(strict_types=1);

namespace Basalto\Core\Http\Middleware;

use Basalto\Core\Support\BackendLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seiten nur für Angemeldete ausserhalb des Backends (Extranet): die Oberfläche spricht die Sprache des Profils —
 * ohne Angabe Deutsch, wie im Backend. Der Browser entscheidet nur auf Gast-Seiten (`SelectGuestLocale`).
 */
final class SelectAccountLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(BackendLocale::of($request->user()) ?? 'de');

        return $next($request);
    }
}
